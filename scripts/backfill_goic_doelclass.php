<?php

use App\Services\GraphService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dryRun = ! in_array('--apply', $argv, true);
$graphIri = 'http://vwm.voorbeeld.nl/data/onderzoek';
$ontologyIri = 'http://vwm.voorbeeld.nl/model/ontologie';
$vwm = 'http://ontologie.politie.nl/def/vwm#';

/** @var GraphService $graph */
$graph = app(GraphService::class);

try {
    $missingRows = $graph->query("
        PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
        SELECT DISTINCT ?goic
        WHERE {
            GRAPH <{$graphIri}> {
                ?goic a vwm:GegevensObjectInContext .
                FILTER NOT EXISTS { ?goic vwm:heeftDoelClass ?existingClass . }
            }
        }
    ");

    if (empty($missingRows)) {
        echo "Alle GOIC's hebben al vwm:heeftDoelClass.\n";
        exit(0);
    }

    $missingGoics = [];
    foreach ($missingRows as $row) {
        $goic = $row['goic'] ?? null;
        if (is_string($goic) && $goic !== '') {
            $missingGoics[$goic] = true;
        }
    }
    $missingGoicUris = array_keys($missingGoics);

    if (empty($missingGoicUris)) {
        echo "Geen bruikbare GOIC-URI's zonder doelclass gevonden.\n";
        exit(0);
    }

    $iriList = implode(' ', array_map(
        fn ($uri) => "<{$uri}>",
        $missingGoicUris
    ));

    // Kern-TB selectie:
    // - actief
    // - TB-class beschrijft een domeinclass
    // - classnaam eindigt op 'Beschrijving' of 'Signalement'
    // - geen rolklasse
    // - geen toestandsweergaveklasse
    $candidateRows = $graph->query("
        PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
        PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        SELECT DISTINCT ?goic ?describedClass ?tbClass
        WHERE {
            GRAPH <{$graphIri}> {
                VALUES ?goic { {$iriList} }

                ?tb a ?tbClass ;
                    vwm:beschrijftGOIC ?goic .
                FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
            }
            GRAPH <{$ontologyIri}> {
                ?tbClass vwm:beschrijftClass ?describedClass .
                FILTER(STRENDS(STR(?tbClass), 'Beschrijving') || STRENDS(STR(?tbClass), 'Signalement'))
                FILTER NOT EXISTS { ?tbClass rdfs:subClassOf* vwm:RolBeschrijving . }
                FILTER NOT EXISTS { ?tbClass rdfs:subClassOf* vwm:ToestandsWeergave . }
            }
        }
    ");

    $classesByGoic = [];
    foreach ($candidateRows as $row) {
        $goic = $row['goic'] ?? null;
        $describedClass = $row['describedClass'] ?? null;
        if (! is_string($goic) || $goic === '' || ! is_string($describedClass) || $describedClass === '') {
            continue;
        }
        if (! isset($classesByGoic[$goic])) {
            $classesByGoic[$goic] = [];
        }
        $classesByGoic[$goic][$describedClass] = true;
    }

    $updates = [];
    $conflicts = [];
    $noKernel = [];

    foreach ($missingGoicUris as $goicUri) {
        $candidateClasses = array_keys($classesByGoic[$goicUri] ?? []);
        if (count($candidateClasses) === 0) {
            $noKernel[] = $goicUri;
            continue;
        }
        if (count($candidateClasses) > 1) {
            sort($candidateClasses);
            $conflicts[$goicUri] = $candidateClasses;
            continue;
        }

        $updates[] = [
            'goic' => $goicUri,
            'class' => $candidateClasses[0],
        ];
    }

    echo 'GOIC zonder doelclass: '.count($missingGoicUris)."\n";
    echo 'Kern-TB kandidaten (uniek): '.count($updates)."\n";
    echo 'Conflicts (>1 kernclass): '.count($conflicts)."\n";
    echo 'Geen kern-TB match: '.count($noKernel)."\n";

    if (! empty($conflicts)) {
        echo "\nConflicts (eerste 20):\n";
        $shown = 0;
        foreach ($conflicts as $goicUri => $classUris) {
            echo "- {$goicUri} => ".implode(', ', $classUris)."\n";
            $shown++;
            if ($shown >= 20) {
                break;
            }
        }
    }

    if (! empty($noKernel)) {
        echo "\nGeen kern-TB match (eerste 20):\n";
        foreach (array_slice($noKernel, 0, 20) as $goicUri) {
            echo "- {$goicUri}\n";
        }
    }

    if (empty($updates)) {
        echo "\nGeen updates uit te voeren.\n";
        exit(0);
    }

    echo "\n".($dryRun ? 'DRY RUN - voorgestelde updates (eerste 20):' : 'Updates uitvoeren (eerste 20):')."\n";
    foreach (array_slice($updates, 0, 20) as $update) {
        echo "- {$update['goic']} => {$update['class']}\n";
    }

    if ($dryRun) {
        echo "\nGeen wijzigingen doorgevoerd. Gebruik --apply om toe te passen.\n";
        exit(0);
    }

    foreach ($updates as $update) {
        $goicUri = $update['goic'];
        $classUri = $update['class'];

        $sparql = "
            PREFIX vwm: <{$vwm}>
            INSERT DATA {
                GRAPH <{$graphIri}> {
                    <{$goicUri}> vwm:heeftDoelClass <{$classUri}> .
                }
            }
        ";

        $graph->update($sparql);
    }

    echo "\nKlaar. Toegevoegde doelclass-triples: ".count($updates)."\n";
} catch (Throwable $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'cURL error 7')) {
        echo "Fout: GraphDB is niet bereikbaar. Start GraphDB en probeer opnieuw.\n";
        exit(1);
    }

    echo "Fout: {$message}\n";
    exit(1);
}
