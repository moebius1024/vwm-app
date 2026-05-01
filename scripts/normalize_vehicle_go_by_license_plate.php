<?php

use App\Services\GraphService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dryRun = ! in_array('--apply', $argv, true);
$graphIri = 'http://vwm.voorbeeld.nl/data/onderzoek';
$vehicleTbClass = 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving';

/** @var GraphService $graph */
$graph = app(GraphService::class);

try {
    $vehicleRows = $graph->query("
        PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
        PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
        SELECT ?goic ?plate
        WHERE {
            GRAPH <{$graphIri}> {
                ?tb a <{$vehicleTbClass}> ;
                    dpm:licensePlate ?plate ;
                    vwm:beschrijftGOIC ?goic .
            }
        }
    ");

    if (empty($vehicleRows)) {
        echo "Geen voertuigbeschrijvingen met kenteken gevonden in GraphDB.\n";
        exit(0);
    }

    $normalizedPlateByGoicUri = [];
    foreach ($vehicleRows as $row) {
        $goicUri = $row['goic'] ?? null;
        $plate = $row['plate'] ?? null;
        if (! is_string($goicUri) || $goicUri === '' || ! is_string($plate) || trim($plate) === '') {
            continue;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '');
        if ($normalized === '') {
            continue;
        }

        $normalizedPlateByGoicUri[$goicUri] = $normalized;
    }

    if (empty($normalizedPlateByGoicUri)) {
        echo "Geen bruikbare kentekens gevonden in GraphDB.\n";
        exit(0);
    }

    $goicUris = array_keys($normalizedPlateByGoicUri);
    $goics = DB::table('gegevens_objecten_in_context')
        ->whereIn('rdf_uri', $goicUris)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get(['id', 'rdf_uri', 'created_at']);

    $goicById = [];
    $goicIdByUri = [];
    foreach ($goics as $goic) {
        $id = (int) $goic->id;
        $uri = (string) $goic->rdf_uri;
        $goicById[$id] = $goic;
        $goicIdByUri[$uri] = $id;
    }

    $normalizedPlateByGoicId = [];
    foreach ($normalizedPlateByGoicUri as $goicUri => $plate) {
        $goicId = $goicIdByUri[$goicUri] ?? null;
        if (! is_int($goicId) || ! isset($goicById[$goicId])) {
            continue;
        }
        $normalizedPlateByGoicId[$goicId] = $plate;
    }

    if (empty($normalizedPlateByGoicId)) {
        echo "Geen bijbehorende SQLite GOIC-records gevonden voor GraphDB voertuigdata.\n";
        exit(0);
    }

    $goicIdsByPlate = [];
    foreach ($normalizedPlateByGoicId as $goicId => $plate) {
        if (! isset($goicById[$goicId])) {
            continue;
        }
        $goicIdsByPlate[$plate][] = $goicId;
    }

    $platesWithDuplicates = array_filter($goicIdsByPlate, function ($ids) {
        return count(array_unique($ids)) > 1;
    });

    if (empty($platesWithDuplicates)) {
        echo "Geen dubbele kentekens met meerdere GOIC's gevonden.\n";
        exit(0);
    }

    echo 'Dubbele kentekens in SQLite (kandidaten): '.count($platesWithDuplicates)."\n";
    foreach ($platesWithDuplicates as $plate => $ids) {
        $uniqueIds = array_values(array_unique($ids));
        sort($uniqueIds);
        echo "- {$plate}: GOIC IDs ".implode(', ', $uniqueIds)."\n";
    }

    $candidateGoicUris = [];
    foreach ($platesWithDuplicates as $ids) {
        foreach ($ids as $goicId) {
            $uri = $goicById[$goicId]->rdf_uri ?? null;
            if (is_string($uri) && $uri !== '') {
                $candidateGoicUris[$uri] = true;
            }
        }
    }

    if (empty($candidateGoicUris)) {
        echo "Geen RDF-URI's gevonden voor kandidaat GOIC's.\n";
        exit(0);
    }

    $iriList = implode(' ', array_map(
        fn ($uri) => "<{$uri}>",
        array_keys($candidateGoicUris)
    ));

    $mappingQuery = "
        PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
        SELECT ?goic ?go
        WHERE {
            GRAPH <{$graphIri}> {
                VALUES ?goic { {$iriList} }
                ?goic vwm:beschrijftGO ?go .
            }
        }
    ";

    $mappingRows = $graph->query($mappingQuery);
    $goByGoicUri = [];
    foreach ($mappingRows as $row) {
        if (! empty($row['goic']) && ! empty($row['go'])) {
            $goByGoicUri[$row['goic']] = $row['go'];
        }
    }

    $updates = [];

    foreach ($platesWithDuplicates as $plate => $ids) {
        $orderedIds = array_values(array_unique($ids));
        usort($orderedIds, function ($a, $b) use ($goicById) {
            $aCreated = (string) ($goicById[$a]->created_at ?? '');
            $bCreated = (string) ($goicById[$b]->created_at ?? '');
            if ($aCreated === $bCreated) {
                return $a <=> $b;
            }

            return $aCreated <=> $bCreated;
        });

        $canonicalGo = null;
        foreach ($orderedIds as $goicId) {
            $goicUri = $goicById[$goicId]->rdf_uri ?? null;
            if (! is_string($goicUri) || $goicUri === '') {
                continue;
            }
            $candidateGo = $goByGoicUri[$goicUri] ?? null;
            if (is_string($candidateGo) && $candidateGo !== '') {
                $canonicalGo = $candidateGo;
                break;
            }
        }

        if (! $canonicalGo) {
            continue;
        }

        foreach ($orderedIds as $goicId) {
            $goicUri = $goicById[$goicId]->rdf_uri ?? null;
            if (! is_string($goicUri) || $goicUri === '') {
                continue;
            }

            $currentGo = $goByGoicUri[$goicUri] ?? null;
            if ($currentGo === $canonicalGo) {
                continue;
            }

            $updates[] = [
                'plate' => $plate,
                'goic_id' => $goicId,
                'goic_uri' => $goicUri,
                'from_go' => $currentGo,
                'to_go' => $canonicalGo,
            ];
        }
    }

    if (empty($updates)) {
        echo "Geen her-koppelingen nodig; alle dubbele kentekens delen al dezelfde GO.\n";
        exit(0);
    }

    echo $dryRun
        ? "DRY RUN: voorgestelde her-koppelingen:\n"
        : "Her-koppelingen uitvoeren:\n";

    foreach ($updates as $update) {
        $from = $update['from_go'] ? "<{$update['from_go']}>" : '(geen huidige GO)';
        echo "- Kenteken {$update['plate']}: GOIC #{$update['goic_id']} {$update['goic_uri']} {$from} -> <{$update['to_go']}>\n";
    }

    if ($dryRun) {
        echo "Geen wijzigingen doorgevoerd. Gebruik --apply om toe te passen.\n";
        exit(0);
    }

    foreach ($updates as $update) {
        $goicUri = $update['goic_uri'];
        $toGo = $update['to_go'];

        $sparql = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            DELETE {
                GRAPH <{$graphIri}> {
                    <{$goicUri}> vwm:beschrijftGO ?oldGo .
                }
            }
            INSERT {
                GRAPH <{$graphIri}> {
                    <{$goicUri}> vwm:beschrijftGO <{$toGo}> .
                }
            }
            WHERE {
                GRAPH <{$graphIri}> {
                    OPTIONAL { <{$goicUri}> vwm:beschrijftGO ?oldGo . }
                }
            }
        ";

        $graph->update($sparql);
    }

    echo 'Klaar. Bijgewerkte koppelingen: '.count($updates)."\n";
} catch (Throwable $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'cURL error 7')) {
        echo "Fout: GraphDB is niet bereikbaar. Start GraphDB en probeer opnieuw.\n";
        exit(1);
    }

    echo "Fout: {$message}\n";
    exit(1);
}
