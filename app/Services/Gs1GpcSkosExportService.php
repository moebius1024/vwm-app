<?php

namespace App\Services;

use DateTimeInterface;
use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\RdfNamespace;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Throwable;

class Gs1GpcSkosExportService
{
    private const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    private const SKOS_NAMESPACE = 'http://www.w3.org/2004/02/skos/core#';

    private const DCTERMS_NAMESPACE = 'http://purl.org/dc/terms/';

    /**
     * @var array<string, array{code: string, label: string, parent: string|null}>
     */
    public const LEVEL_COLUMNS = [
        'segments' => [
            'code' => 'SegmentCode',
            'label' => 'SegmentTitle',
            'parent' => null,
        ],
        'families' => [
            'code' => 'FamilyCode',
            'label' => 'FamilyTitle',
            'parent' => 'SegmentCode',
        ],
        'classes' => [
            'code' => 'ClassCode',
            'label' => 'ClassTitle',
            'parent' => 'FamilyCode',
        ],
        'bricks' => [
            'code' => 'BrickCode',
            'label' => 'BrickTitle',
            'parent' => 'ClassCode',
        ],
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_COLUMNS = [
        'SegmentCode',
        'SegmentTitle',
        'FamilyCode',
        'FamilyTitle',
        'ClassCode',
        'ClassTitle',
        'BrickCode',
        'BrickTitle',
    ];

    private readonly string $schemeUri;

    private readonly string $conceptBaseUri;

    private readonly string $baseUri;

    public function __construct(private readonly Filesystem $files, ?string $baseUri = null)
    {
        $configuredBaseUri = rtrim(trim($baseUri ?? (string) config('reference.gpc.base_uri')), '/').'/';
        if (filter_var($configuredBaseUri, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('De GS1 GPC base URI is ongeldig.');
        }

        $this->baseUri = $configuredBaseUri;
        $this->schemeUri = $this->baseUri.'scheme';
        $this->conceptBaseUri = $this->baseUri.'concept/';
    }

    /**
     * @return array{
     *     worksheet: string,
     *     segments: int,
     *     families: int,
     *     classes: int,
     *     bricks: int,
     *     concepts: int,
     *     skipped_rows: int,
     *     conflicting_labels: int,
     *     conflicting_levels: int,
     *     conflicting_parents: int,
     *     conflicting_label_codes: list<string>,
     *     conflicting_level_codes: list<string>,
     *     conflicting_parent_codes: list<string>,
     *     triples: int,
     *     output_path: string,
     *     file_size: int|null,
     *     written: bool
     * }
     */
    public function export(string $sourcePath, string $outputPath, bool $allowConflicts = false): array
    {
        $this->assertReadableSource($sourcePath);

        $concepts = [];
        $counts = array_fill_keys(array_keys(self::LEVEL_COLUMNS), 0);
        $conflicts = [
            'labels' => [],
            'levels' => [],
            'parents' => [],
        ];
        $skippedRows = 0;
        $worksheet = null;
        $headerCandidates = [];
        $reader = new Reader;

        try {
            $reader->open($sourcePath);

            foreach ($reader->getSheetIterator() as $sheet) {
                $headerMap = null;

                foreach ($sheet->getRowIterator() as $row) {
                    if ($headerMap === null) {
                        if ($row->isEmpty()) {
                            continue;
                        }

                        $headerMap = $this->headerMap($row->toArray());
                        $missingColumns = $this->missingColumns($headerMap);
                        $headerCandidates[$sheet->getName()] = array_keys($headerMap);

                        if ($missingColumns !== []) {
                            break;
                        }

                        $worksheet = $sheet->getName();

                        continue;
                    }

                    if ($row->isEmpty()) {
                        $skippedRows++;

                        continue;
                    }

                    $values = $this->rowValues($row->toArray(), $headerMap);
                    if (! $this->hasCompleteHierarchy($values)) {
                        $skippedRows++;

                        continue;
                    }

                    foreach (self::LEVEL_COLUMNS as $level => $columns) {
                        $code = $values[$columns['code']];
                        $label = $values[$columns['label']];
                        $parentCode = $columns['parent'] === null
                            ? null
                            : $values[$columns['parent']];

                        if (isset($concepts[$code])) {
                            $this->recordDuplicateConflicts(
                                $concepts[$code],
                                $level,
                                $label,
                                $parentCode,
                                $code,
                                $conflicts,
                            );

                            continue;
                        }

                        $concepts[$code] = [
                            'level' => $level,
                            'label' => $label,
                            'parent_code' => $parentCode,
                        ];
                        $counts[$level]++;
                    }
                }

                if ($worksheet !== null) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                "Het Excel-bestand kon niet worden gelezen: {$exception->getMessage()}",
                previous: $exception,
            );
        } finally {
            $reader->close();
        }

        if ($worksheet === null) {
            throw new RuntimeException($this->missingWorksheetMessage($headerCandidates));
        }

        if ($concepts === []) {
            throw new RuntimeException('Het werkblad bevat geen volledige Segment > Family > Class > Brick-rijen.');
        }

        $result = [
            'worksheet' => $worksheet,
            'segments' => $counts['segments'],
            'families' => $counts['families'],
            'classes' => $counts['classes'],
            'bricks' => $counts['bricks'],
            'concepts' => count($concepts),
            'skipped_rows' => $skippedRows,
            'conflicting_labels' => count($conflicts['labels']),
            'conflicting_levels' => count($conflicts['levels']),
            'conflicting_parents' => count($conflicts['parents']),
            'conflicting_label_codes' => array_keys($conflicts['labels']),
            'conflicting_level_codes' => array_keys($conflicts['levels']),
            'conflicting_parent_codes' => array_keys($conflicts['parents']),
            'triples' => 0,
            'output_path' => $outputPath,
            'file_size' => null,
            'written' => false,
        ];

        if ($this->hasConflicts($conflicts) && ! $allowConflicts) {
            return $result;
        }

        $graph = $this->buildGraph($concepts, $sourcePath);
        $turtle = $graph->serialise('turtle');

        if (! is_string($turtle)) {
            throw new RuntimeException('De SKOS-grafiek kon niet als Turtle worden geserialiseerd.');
        }

        $this->files->ensureDirectoryExists(dirname($outputPath));
        $this->files->replace($outputPath, $turtle);

        return array_merge($result, [
            'triples' => $graph->countTriples(),
            'file_size' => $this->files->size($outputPath),
            'written' => true,
        ]);
    }

    private function assertReadableSource(string $sourcePath): void
    {
        if (! $this->files->exists($sourcePath)) {
            throw new InvalidArgumentException("Het Excel-bestand bestaat niet: {$sourcePath}");
        }

        if (! $this->files->isReadable($sourcePath)) {
            throw new InvalidArgumentException("Het Excel-bestand is niet leesbaar: {$sourcePath}");
        }

        if (Str::lower($this->files->extension($sourcePath)) !== 'xlsx') {
            throw new InvalidArgumentException('Het bronbestand moet een .xlsx-bestand zijn.');
        }
    }

    /**
     * @param  list<mixed>  $cells
     * @return array<string, int>
     */
    private function headerMap(array $cells): array
    {
        $requiredByNormalizedName = [];
        foreach (self::REQUIRED_COLUMNS as $column) {
            $requiredByNormalizedName[$this->normalizeHeader($column)] = $column;
        }

        $headerMap = [];
        foreach ($cells as $index => $cell) {
            $normalizedHeader = $this->normalizeHeader($this->cellString($cell));
            if (isset($requiredByNormalizedName[$normalizedHeader])) {
                $headerMap[$requiredByNormalizedName[$normalizedHeader]] = $index;
            }
        }

        return $headerMap;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->replace("\u{FEFF}", '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    /**
     * @param  array<string, int>  $headerMap
     * @return list<string>
     */
    private function missingColumns(array $headerMap): array
    {
        return array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($headerMap)));
    }

    /**
     * @param  list<mixed>  $cells
     * @param  array<string, int>  $headerMap
     * @return array<string, string>
     */
    private function rowValues(array $cells, array $headerMap): array
    {
        $values = [];
        foreach ($headerMap as $column => $index) {
            $values[$column] = $this->cellString($cells[$index] ?? null);
        }

        return $values;
    }

    private function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_float($value) && floor($value) === $value) {
            return number_format($value, 0, '.', '');
        }

        if (! is_scalar($value)) {
            return '';
        }

        return Str::of((string) $value)->trim()->toString();
    }

    /**
     * @param  array<string, string>  $values
     */
    private function hasCompleteHierarchy(array $values): bool
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (($values[$column] ?? '') === '') {
                return false;
            }
        }

        foreach (self::LEVEL_COLUMNS as $columns) {
            if (preg_match('/^\d+$/', $values[$columns['code']]) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{level: string, label: string, parent_code: string|null}  $existingConcept
     * @param  array{labels: array<string, true>, levels: array<string, true>, parents: array<string, true>}  $conflicts
     */
    private function recordDuplicateConflicts(
        array $existingConcept,
        string $level,
        string $label,
        ?string $parentCode,
        string $code,
        array &$conflicts,
    ): void {
        if ($existingConcept['label'] !== $label) {
            $conflicts['labels'][$code] = true;
        }

        if ($existingConcept['level'] !== $level) {
            $conflicts['levels'][$code] = true;
        }

        if ($existingConcept['parent_code'] !== $parentCode) {
            $conflicts['parents'][$code] = true;
        }
    }

    /**
     * @param  array{labels: array<string, true>, levels: array<string, true>, parents: array<string, true>}  $conflicts
     */
    private function hasConflicts(array $conflicts): bool
    {
        return $conflicts['labels'] !== []
            || $conflicts['levels'] !== []
            || $conflicts['parents'] !== [];
    }

    /**
     * @param  array<string, array{level: string, label: string, parent_code: string|null}>  $concepts
     */
    private function buildGraph(array $concepts, string $sourcePath): Graph
    {
        RdfNamespace::set('skos', self::SKOS_NAMESPACE);
        RdfNamespace::set('dcterms', self::DCTERMS_NAMESPACE);
        RdfNamespace::set('gpc', $this->baseUri);

        $graph = new Graph($this->schemeUri);
        $graph->addResource($this->schemeUri, self::RDF_TYPE, self::SKOS_NAMESPACE.'ConceptScheme');
        $graph->addLiteral($this->schemeUri, self::SKOS_NAMESPACE.'prefLabel', 'GS1 GPC Nederlands', 'nl');
        $graph->addLiteral(
            $this->schemeUri,
            self::DCTERMS_NAMESPACE.'title',
            'GS1 Global Product Classification (GPC) – Nederlands',
            'nl',
        );
        $graph->addLiteral(
            $this->schemeUri,
            self::DCTERMS_NAMESPACE.'description',
            'Nederlandse GS1 GPC-hiërarchie met de niveaus Segment, Family, Class en Brick.',
            'nl',
        );
        $graph->addLiteral($this->schemeUri, self::DCTERMS_NAMESPACE.'creator', 'GS1');
        $graph->addLiteral($this->schemeUri, self::DCTERMS_NAMESPACE.'source', basename($sourcePath));

        foreach ($concepts as $code => $concept) {
            $conceptUri = $this->conceptUri($code);
            $graph->addResource($conceptUri, self::RDF_TYPE, self::SKOS_NAMESPACE.'Concept');
            $graph->addLiteral($conceptUri, self::SKOS_NAMESPACE.'prefLabel', $concept['label'], 'nl');
            $graph->addLiteral($conceptUri, self::SKOS_NAMESPACE.'notation', new Literal($code));
            $graph->addResource($conceptUri, self::SKOS_NAMESPACE.'inScheme', $this->schemeUri);

            if ($concept['parent_code'] === null) {
                $graph->addResource($conceptUri, self::SKOS_NAMESPACE.'topConceptOf', $this->schemeUri);
                $graph->addResource($this->schemeUri, self::SKOS_NAMESPACE.'hasTopConcept', $conceptUri);

                continue;
            }

            $graph->addResource(
                $conceptUri,
                self::SKOS_NAMESPACE.'broader',
                $this->conceptUri($concept['parent_code']),
            );
        }

        return $graph;
    }

    private function conceptUri(string $code): string
    {
        return $this->conceptBaseUri.$code;
    }

    /**
     * @param  array<string, list<string>>  $headerCandidates
     */
    private function missingWorksheetMessage(array $headerCandidates): string
    {
        $required = implode(', ', self::REQUIRED_COLUMNS);

        if ($headerCandidates === []) {
            return "Het Excel-bestand bevat geen leesbaar werkblad. Vereiste kolommen: {$required}.";
        }

        $worksheets = implode(', ', array_keys($headerCandidates));

        return "Geen werkblad met alle noodzakelijke kolommen gevonden. Werkbladen: {$worksheets}. Vereist: {$required}.";
    }
}
