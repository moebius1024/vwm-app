<?php

use App\Services\GraphService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$graph = app(GraphService::class);

$dossiers = DB::table('dossiers')
    ->whereNull('rdf_uri')
    ->orWhere('rdf_uri', '')
    ->get();

if ($dossiers->isEmpty()) {
    echo "No dossiers to backfill.\n";
    exit(0);
}

$triples = '';
$updated = 0;

foreach ($dossiers as $dossier) {
    $uuid = $dossier->uuid ?: (string) Str::uuid();
    $rdfUri = "http://vwm.voorbeeld.nl/data/dossier/{$uuid}";

    DB::table('dossiers')
        ->where('id', $dossier->id)
        ->update([
            'uuid' => $uuid,
            'rdf_uri' => $rdfUri,
            'updated_at' => now(),
        ]);

    $triples .= "<{$rdfUri}> a <http://ontologie.politie.nl/def/vwm#Dossier> .\n";
    $updated++;
}

if ($triples !== '') {
    $sparql = "INSERT DATA { GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> { {$triples} } }";
    $graph->update($sparql);
}

echo "Backfilled {$updated} dossier(s).\n";
