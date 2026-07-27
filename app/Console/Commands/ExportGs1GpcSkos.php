<?php

namespace App\Console\Commands;

use App\Services\Gs1GpcSkosExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Number;
use RuntimeException;
use Throwable;

#[Signature('gs1-gpc:export-skos
    {source? : Pad naar het Nederlandse GS1 GPC .xlsx-bestand}
    {--output= : Uitvoerpad voor het Turtle-bestand}
    {--allow-conflicts : Schrijf met expliciete first-value-keuze ondanks bronconflicten}')]
#[Description('Converteer Nederlandse GS1 GPC-niveaus naar een SKOS/Turtle-bestand')]
class ExportGs1GpcSkos extends Command
{
    public function __construct(
        private readonly Gs1GpcSkosExportService $exportService,
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $sourcePath = $this->sourcePath();
            $outputPath = $this->outputPath();
            $result = $this->exportService->export(
                $sourcePath,
                $outputPath,
                (bool) $this->option('allow-conflicts'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Onderdeel', 'Aantal'],
            [
                ['Segments', $result['segments']],
                ['Families', $result['families']],
                ['Classes', $result['classes']],
                ['Bricks', $result['bricks']],
                ['Concepten totaal', $result['concepts']],
                ['Overgeslagen rijen', $result['skipped_rows']],
                ['Conflicterende labels', $result['conflicting_labels']],
                ['Conflicterende niveaus', $result['conflicting_levels']],
                ['Conflicterende parents', $result['conflicting_parents']],
                ['Triples', $result['triples']],
            ],
        );
        $this->line("Beoogd Turtle-bestand: {$result['output_path']}");
        $this->reportConflictCodes('Labelconflicten', $result['conflicting_label_codes']);
        $this->reportConflictCodes('Niveauconflicten', $result['conflicting_level_codes']);
        $this->reportConflictCodes('Parentconflicten', $result['conflicting_parent_codes']);

        if (! $result['written']) {
            $this->components->error(
                'Er zijn structurele conflicten gevonden. Er is geen Turtle-bestand geschreven. Gebruik alleen na beoordeling --allow-conflicts.',
            );

            return self::FAILURE;
        }

        $this->components->info("GS1 GPC is geëxporteerd vanuit werkblad '{$result['worksheet']}'.");
        $this->line('Bestandsgrootte: '.Number::fileSize($result['file_size'])." ({$result['file_size']} bytes)");

        return self::SUCCESS;
    }

    private function sourcePath(): string
    {
        $source = $this->argument('source');
        if (is_string($source) && trim($source) !== '') {
            return $this->absolutePath($source);
        }

        $sourceDirectory = base_path('docs/SKOS_Begrippen');
        $candidates = $this->files->glob($sourceDirectory.'/*.xlsx');

        if (count($candidates) !== 1) {
            throw new RuntimeException(
                'Zonder source-argument moet docs/SKOS_Begrippen exact één .xlsx-bestand bevatten.',
            );
        }

        return $candidates[0];
    }

    private function outputPath(): string
    {
        $output = $this->option('output');
        if (! is_string($output) || trim($output) === '') {
            return storage_path('app/exports/gs1-gpc-nl.ttl');
        }

        return $this->absolutePath($output);
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param  list<string>  $codes
     */
    private function reportConflictCodes(string $label, array $codes): void
    {
        if ($codes !== []) {
            $this->warn($label.': '.implode(', ', $codes));
        }
    }
}
