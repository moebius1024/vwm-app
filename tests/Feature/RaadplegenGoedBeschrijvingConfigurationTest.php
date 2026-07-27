<?php

use Illuminate\Support\Facades\DB;

it('configures the goods description template for RaadplegenCase', function () {
    $transactieId = DB::table('transactie_soorten')->insertGetId([
        'id' => 5,
        'naam' => 'RaadplegenCase',
        'rdf_uri' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('database/migrations/2026_07_27_125607_add_goed_beschrijving_to_raadplegen_case_transactie.php');

    $migration->up();
    $migration->up();

    $mapping = DB::table('transactie_soort_sjabloon')
        ->where('transactie_soort_id', $transactieId)
        ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#GoedBeschrijving')
        ->where('type', 'sjabloon')
        ->get(['crud_flags', 'volgorde']);

    expect($mapping)->toHaveCount(1)
        ->and($mapping->first()->crud_flags)->toBe('R')
        ->and($mapping->first()->volgorde)->toBe(5);
});
