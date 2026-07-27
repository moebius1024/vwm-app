<?php

use App\Services\Gs1GpcSkosExportService;
use EasyRdf\Graph;
use EasyRdf\Serialiser\Turtle;
use Illuminate\Filesystem\Filesystem;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

const TEST_REFERENCE_BASE_URI = 'https://reference.test/gpc';
const SKOS = 'http://www.w3.org/2004/02/skos/core#';

/**
 * @param  list<list<null|bool|float|int|string>>  $rows
 */
function createGs1Workbook(array $rows, ?array $headers = null): array
{
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/vwm-gs1-'.bin2hex(random_bytes(8));
    $files->ensureDirectoryExists($directory);
    $sourcePath = $directory.'/source.xlsx';
    $outputPath = $directory.'/output.ttl';
    $writer = new Writer;
    $writer->openToFile($sourcePath);
    $writer->addRow(Row::fromValues($headers ?? [
        'SegmentCode',
        'SegmentTitle',
        'FamilyCode',
        'FamilyTitle',
        'ClassCode',
        'ClassTitle',
        'BrickCode',
        'BrickTitle',
        'AttributeCode',
        'AttributeTitle',
    ]));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return [$directory, $sourcePath, $outputPath];
}

/**
 * @return array{type?: string, value?: string, lang?: string, datatype?: string}
 */
function rdfObject(Graph $graph, string $subject, string $predicate): array
{
    return $graph->toRdfPhp()[$subject][$predicate][0] ?? [];
}

test('it fixes the column mapping to the headers found in the Dutch GS1 workbook', function () {
    expect(Gs1GpcSkosExportService::LEVEL_COLUMNS)->toBe([
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
    ]);
});

test('it deduplicates GS1 codes and creates the expected SKOS hierarchy and valid Turtle', function () {
    $specialLabel = "Brood \"speciaal\" \\ lijn\nCrème 🍞";
    [$directory, $sourcePath, $outputPath] = createGs1Workbook([
        ['68000000', 'Voeding & dranken', '68010000', 'Bakkerij', '68010100', 'Brood', '10001234', $specialLabel, '20000001', 'Kleur'],
        ['68000000', 'Voeding & dranken', '68010000', 'Bakkerij', '68010100', 'Brood', '10001234', $specialLabel, '20000002', 'Formaat'],
        ['68000000', 'Voeding & dranken', '68010000', 'Bakkerij', '68010100', 'Brood', '10005678', 'Banket', null, null],
        ['68000000', 'Voeding & dranken', '68010000', 'Bakkerij', '68010100', 'Brood', null, null, null, null],
    ]);

    try {
        $service = new Gs1GpcSkosExportService(new Filesystem, TEST_REFERENCE_BASE_URI);
        $result = $service->export($sourcePath, $outputPath);

        expect($result)->toMatchArray([
            'worksheet' => 'Sheet1',
            'segments' => 1,
            'families' => 1,
            'classes' => 1,
            'bricks' => 2,
            'concepts' => 5,
            'skipped_rows' => 1,
            'conflicting_labels' => 0,
            'conflicting_levels' => 0,
            'conflicting_parents' => 0,
            'triples' => 32,
            'written' => true,
        ]);

        $turtle = file_get_contents($outputPath);
        expect($turtle)->toBeString()->not->toBeEmpty();

        $graph = new Graph(TEST_REFERENCE_BASE_URI.'/scheme');
        $parsedTriples = $graph->parse($turtle, 'turtle', TEST_REFERENCE_BASE_URI.'/scheme');

        $schemeUri = TEST_REFERENCE_BASE_URI.'/scheme';
        $conceptBaseUri = TEST_REFERENCE_BASE_URI.'/concept/';
        $segmentUri = $conceptBaseUri.'68000000';
        $familyUri = $conceptBaseUri.'68010000';
        $classUri = $conceptBaseUri.'68010100';
        $brickUri = $conceptBaseUri.'10001234';
        $secondBrickUri = $conceptBaseUri.'10005678';

        expect($parsedTriples)->toBe(32)
            ->and($graph->allOfType(SKOS.'Concept'))->toHaveCount(5)
            ->and(rdfObject($graph, $familyUri, SKOS.'broader')['value'])->toBe($segmentUri)
            ->and(rdfObject($graph, $classUri, SKOS.'broader')['value'])->toBe($familyUri)
            ->and(rdfObject($graph, $brickUri, SKOS.'broader')['value'])->toBe($classUri)
            ->and(rdfObject($graph, $segmentUri, SKOS.'topConceptOf')['value'])->toBe($schemeUri)
            ->and(rdfObject($graph, $schemeUri, SKOS.'hasTopConcept')['value'])->toBe($segmentUri)
            ->and(rdfObject($graph, $brickUri, SKOS.'inScheme')['value'])->toBe($schemeUri)
            ->and(rdfObject($graph, $brickUri, SKOS.'notation'))->toBe([
                'type' => 'literal',
                'value' => '10001234',
            ])
            ->and(rdfObject($graph, $secondBrickUri, SKOS.'prefLabel'))->toBe([
                'type' => 'literal',
                'value' => 'Banket',
                'lang' => 'nl',
            ])
            ->and($turtle)->toContain(Turtle::quotedString($specialLabel).'@nl')
            ->and($turtle)->not->toContain('example.org')
            ->and($turtle)->not->toContain(TEST_REFERENCE_BASE_URI.'scheme');

        foreach ($graph->allOfType(SKOS.'Concept') as $concept) {
            $conceptUri = (string) $concept;
            $notation = rdfObject($graph, $conceptUri, SKOS.'notation')['value'];

            expect($conceptUri)->toStartWith($conceptBaseUri)
                ->and(substr($conceptUri, strlen($conceptBaseUri)))->toBe($notation);
        }

        foreach ($graph->toRdfPhp() as $predicates) {
            expect(array_keys($predicates))->not->toContain(SKOS.'narrower');
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

test('it reports unique label level and parent conflicts without writing Turtle', function () {
    [$directory, $sourcePath, $outputPath] = createGs1Workbook([
        ['68000000', 'Segment A', '68010000', 'Family A', '68010100', 'Class A', '10001234', 'Brick A'],
        ['69000000', 'Segment B', '68010000', 'Family A', '69010100', 'Class B', '10001234', 'Brick gewijzigd'],
        ['68000000', 'Segment A', '68010100', 'Zelfde code als class', '68010100', 'Class A', '10009999', 'Brick C'],
    ]);

    try {
        $service = new Gs1GpcSkosExportService(new Filesystem, TEST_REFERENCE_BASE_URI);
        $result = $service->export($sourcePath, $outputPath);

        expect($result)->toMatchArray([
            'conflicting_labels' => 2,
            'conflicting_levels' => 1,
            'conflicting_parents' => 3,
            'conflicting_label_codes' => ['10001234', '68010100'],
            'conflicting_level_codes' => ['68010100'],
            'conflicting_parent_codes' => ['68010000', '10001234', '68010100'],
            'written' => false,
            'file_size' => null,
            'triples' => 0,
        ])->and(file_exists($outputPath))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

test('it fails clearly when necessary workbook columns are missing', function () {
    [$directory, $sourcePath, $outputPath] = createGs1Workbook(
        [['68000000', 'Segment']],
        ['SegmentCode', 'SegmentTitle'],
    );

    try {
        $service = new Gs1GpcSkosExportService(new Filesystem, TEST_REFERENCE_BASE_URI);

        expect(fn () => $service->export($sourcePath, $outputPath))
            ->toThrow(RuntimeException::class, 'Geen werkblad met alle noodzakelijke kolommen gevonden');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
