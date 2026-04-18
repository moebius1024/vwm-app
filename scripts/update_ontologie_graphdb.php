<?php

use Illuminate\Contracts\Console\Kernel;
use App\Services\GraphService;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ttlPath = __DIR__ . '/../Docs/statements.ttl';
if (!file_exists($ttlPath)) {
    echo "Ontology file not found: {$ttlPath}\n";
    exit(1);
}

$ttl = trim(file_get_contents($ttlPath));
if ($ttl === '') {
    echo "Ontology file is empty.\n";
    exit(1);
}

$shapesPath = __DIR__ . '/../Docs/shapes.ttl';
if (!file_exists($shapesPath)) {
    echo "Shapes file not found: {$shapesPath}\n";
    exit(1);
}

$shapesRaw = trim(file_get_contents($shapesPath));
if ($shapesRaw === '') {
    echo "Shapes file is empty.\n";
    exit(1);
}

$graph = app(GraphService::class);
$graphIri = 'http://vwm.voorbeeld.nl/model/ontologie';

$insert = "
    INSERT DATA {
        GRAPH <{$graphIri}> {
            {$ttl}
        }
    }
";

try {
    $graph->update("CLEAR GRAPH <{$graphIri}>");
    $graph->update($insert);
    $graph->update(buildShapesInsert($shapesRaw, $graphIri));
    echo "Ontologie bijgewerkt in GraphDB.\n";
} catch (Exception $e) {
    echo "GraphDB update fout: {$e->getMessage()}\n";
    exit(1);
}

function buildShapesInsert(string $shapesRaw, string $graphIri): string
{
    $lines = preg_split('/\r\n|\r|\n/', $shapesRaw);
    $prefixes = [];
    $body = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, '@prefix')) {
            // @prefix sh: <http://www.w3.org/ns/shacl#> .
            $parts = preg_split('/\s+/', $trimmed);
            if (count($parts) >= 3) {
                $prefix = rtrim($parts[1], ':');
                $iri = trim($parts[2], '<>'); // remove <> if present
                $prefixes[] = "PREFIX {$prefix}: <{$iri}>";
            }
            continue;
        }
        $body[] = $line;
    }

    $prefixBlock = $prefixes ? implode("\n", array_unique($prefixes)) . "\n" : '';
    $bodyBlock = implode("\n", $body);

    return "{$prefixBlock}\nINSERT DATA { GRAPH <{$graphIri}> { {$bodyBlock} } }";
}
