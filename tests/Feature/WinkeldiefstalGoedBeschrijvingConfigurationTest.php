<?php

use Illuminate\Support\Facades\DB;

it('configures the goods description template for Winkeldiefstal', function () {
    $transactieId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Winkeldiefstal',
        'rdf_uri' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('database/migrations/2026_07_27_124957_add_goed_beschrijving_to_winkeldiefstal_transactie.php');

    $migration->up();
    $migration->up();

    $mapping = DB::table('transactie_soort_sjabloon')
        ->join('transactie_soorten', 'transactie_soorten.id', '=', 'transactie_soort_sjabloon.transactie_soort_id')
        ->where('transactie_soorten.naam', 'Winkeldiefstal')
        ->where('transactie_soort_sjabloon.sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#GoedBeschrijving')
        ->where('transactie_soort_sjabloon.type', 'sjabloon')
        ->get([
            'transactie_soort_sjabloon.crud_flags',
            'transactie_soort_sjabloon.volgorde',
        ]);

    expect($mapping)->toHaveCount(1)
        ->and($transactieId)->toBeGreaterThan(0)
        ->and($mapping->first()->crud_flags)->toBe('CRUD')
        ->and($mapping->first()->volgorde)->toBe(5);
});
