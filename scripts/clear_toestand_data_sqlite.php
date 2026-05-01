<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dryRun = ! in_array('--apply', $argv, true);

$query = DB::table('toestands_beschrijvingen')
    ->whereNotNull('toestand_data')
    ->where('toestand_data', '!=', '');

$count = (int) $query->count();

if ($count === 0) {
    echo "Geen rijen met inhoud in toestand_data.\n";
    exit(0);
}

echo "Rijen met inhoud in toestand_data: {$count}\n";

if ($dryRun) {
    echo "Dry-run: geen wijzigingen doorgevoerd. Gebruik --apply om op te schonen.\n";
    exit(0);
}

$updated = DB::table('toestands_beschrijvingen')
    ->whereNotNull('toestand_data')
    ->where('toestand_data', '!=', '')
    ->update([
        'toestand_data' => null,
        'updated_at' => now(),
    ]);

echo "Opgeschoonde rijen: {$updated}\n";
