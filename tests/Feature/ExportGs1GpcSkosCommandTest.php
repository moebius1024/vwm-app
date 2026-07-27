<?php

use App\Services\Gs1GpcSkosExportService;
use EasyRdf\Graph;
use Illuminate\Filesystem\Filesystem;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

use function Pest\Laravel\mock;

function createGs1CommandWorkbook(string $directory): string
{
    $sourcePath = $directory.'/command-source.xlsx';
    $writer = new Writer;
    $writer->openToFile($sourcePath);
    $writer->addRow(Row::fromValues([
        'SegmentCode',
        'SegmentTitle',
        'FamilyCode',
        'FamilyTitle',
        'ClassCode',
        'ClassTitle',
        'BrickCode',
        'BrickTitle',
    ]));
    $writer->addRow(Row::fromValues([
        '68000000',
        'Voeding',
        '68010000',
        'Bakkerij',
        '68010100',
        'Brood',
        '10001234',
        'Volkorenbrood',
    ]));
    $writer->close();

    return $sourcePath;
}

test('the command exports GS1 GPC to a configurable Turtle path and reports counts', function () {
    $files = new Filesystem;
    $directory = storage_path('framework/testing/gs1-command-'.bin2hex(random_bytes(8)));
    $files->ensureDirectoryExists($directory);
    $sourcePath = createGs1CommandWorkbook($directory);
    $outputPath = $directory.'/custom-output.ttl';

    try {
        $this->artisan('gs1-gpc:export-skos', [
            'source' => $sourcePath,
            '--output' => $outputPath,
        ])
            ->expectsOutputToContain('Segments')
            ->expectsOutputToContain('Conflicterende parents')
            ->expectsOutputToContain('Bestandsgrootte')
            ->assertSuccessful();

        $turtle = $files->get($outputPath);
        $schemeUri = 'http://ontologie.politie.nl/ref/gpc/scheme';
        $conceptBaseUri = 'http://ontologie.politie.nl/ref/gpc/concept/';
        $graph = new Graph($schemeUri);

        expect(config('reference.gpc.base_uri'))->toBe('http://ontologie.politie.nl/ref/gpc/')
            ->and($graph->parse($turtle, 'turtle', $schemeUri))->toBeGreaterThan(0)
            ->and(array_map(
                static fn ($resource): string => (string) $resource,
                $graph->allOfType('http://www.w3.org/2004/02/skos/core#ConceptScheme'),
            ))->toBe([$schemeUri])
            ->and(array_map(
                static fn ($resource): string => (string) $resource,
                $graph->allOfType('http://www.w3.org/2004/02/skos/core#Concept'),
            ))->each->toStartWith($conceptBaseUri)
            ->and($turtle)->toContain('@prefix gpc: <http://ontologie.politie.nl/ref/gpc/>')
            ->and($turtle)->not->toContain('example.org');
    } finally {
        $files->deleteDirectory($directory);
    }
});

test('the command applies a configured GPC base URI without requiring a trailing slash', function () {
    $files = new Filesystem;
    $directory = storage_path('framework/testing/gs1-config-'.bin2hex(random_bytes(8)));
    $files->ensureDirectoryExists($directory);
    $sourcePath = createGs1CommandWorkbook($directory);
    $outputPath = $directory.'/configured-output.ttl';
    $configuredBaseUri = 'https://reference.test/custom-gpc';
    config(['reference.gpc.base_uri' => $configuredBaseUri]);

    try {
        $this->artisan('gs1-gpc:export-skos', [
            'source' => $sourcePath,
            '--output' => $outputPath,
        ])->assertSuccessful();

        $turtle = $files->get($outputPath);
        $schemeUri = $configuredBaseUri.'/scheme';
        $conceptUri = $configuredBaseUri.'/concept/10001234';
        $graph = new Graph($schemeUri);
        $graph->parse($turtle, 'turtle', $schemeUri);
        $notation = $graph->toRdfPhp()[$conceptUri]['http://www.w3.org/2004/02/skos/core#notation'][0]['value'] ?? null;

        expect($graph->resource($schemeUri)->isA('http://www.w3.org/2004/02/skos/core#ConceptScheme'))->toBeTrue()
            ->and($notation)->toBe('10001234')
            ->and($turtle)->not->toContain($configuredBaseUri.'scheme')
            ->and($turtle)->not->toContain('example.org');
    } finally {
        $files->deleteDirectory($directory);
    }
});

test('the command uses the inspected workbook and required default Turtle path', function () {
    $sourcePath = base_path('docs/SKOS_Begrippen/GPC as of May 2026 (concept voor nov 2026) v20260520 NL.xlsx');
    $outputPath = storage_path('app/exports/gs1-gpc-nl.ttl');
    $service = mock(Gs1GpcSkosExportService::class);
    $service->shouldReceive('export')
        ->once()
        ->with($sourcePath, $outputPath, false)
        ->andReturn([
            'worksheet' => 'Schema',
            'segments' => 1,
            'families' => 1,
            'classes' => 1,
            'bricks' => 1,
            'concepts' => 4,
            'skipped_rows' => 0,
            'conflicting_labels' => 0,
            'conflicting_levels' => 0,
            'conflicting_parents' => 0,
            'conflicting_label_codes' => [],
            'conflicting_level_codes' => [],
            'conflicting_parent_codes' => [],
            'triples' => 27,
            'output_path' => $outputPath,
            'file_size' => 1024,
            'written' => true,
        ]);

    $this->artisan('gs1-gpc:export-skos')
        ->expectsOutputToContain($outputPath)
        ->assertSuccessful();
});

test('the command fails clearly for a missing source file', function () {
    $missingPath = storage_path('framework/testing/does-not-exist.xlsx');

    $this->artisan('gs1-gpc:export-skos', ['source' => $missingPath])
        ->expectsOutputToContain('Het Excel-bestand bestaat niet')
        ->assertFailed();
});

test('the command stops on structural conflicts unless they are explicitly allowed', function () {
    $files = new Filesystem;
    $directory = storage_path('framework/testing/gs1-conflict-'.bin2hex(random_bytes(8)));
    $files->ensureDirectoryExists($directory);
    $sourcePath = $directory.'/conflict-source.xlsx';
    $outputPath = $directory.'/conflict-output.ttl';
    $writer = new Writer;
    $writer->openToFile($sourcePath);
    $writer->addRows([
        Row::fromValues(['SegmentCode', 'SegmentTitle', 'FamilyCode', 'FamilyTitle', 'ClassCode', 'ClassTitle', 'BrickCode', 'BrickTitle']),
        Row::fromValues(['68000000', 'Segment A', '68010000', 'Family', '68010100', 'Class', '10001234', 'Brick']),
        Row::fromValues(['69000000', 'Segment B', '68010000', 'Family', '69010100', 'Class B', '10005678', 'Brick B']),
    ]);
    $writer->close();

    try {
        $this->artisan('gs1-gpc:export-skos', [
            'source' => $sourcePath,
            '--output' => $outputPath,
        ])
            ->expectsOutputToContain('structurele conflicten')
            ->assertFailed();

        expect($files->exists($outputPath))->toBeFalse();

        $this->artisan('gs1-gpc:export-skos', [
            'source' => $sourcePath,
            '--output' => $outputPath,
            '--allow-conflicts' => true,
        ])->assertSuccessful();

        expect($files->exists($outputPath))->toBeTrue();
    } finally {
        $files->deleteDirectory($directory);
    }
});
