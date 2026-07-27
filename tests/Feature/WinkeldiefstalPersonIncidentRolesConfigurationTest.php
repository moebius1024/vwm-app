<?php

use Illuminate\Support\Facades\DB;

it('configures person to incident role templates for Winkeldiefstal', function () {
    DB::table('transactie_soorten')->insert([
        'naam' => 'Winkeldiefstal',
        'rdf_uri' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('database/migrations/2026_07_27_140705_add_person_incident_roles_to_winkeldiefstal_transaction.php');

    $migration->up();
    $migration->up();

    $rollen = DB::table('transactie_soort_sjabloon')
        ->join('transactie_soorten', 'transactie_soorten.id', '=', 'transactie_soort_sjabloon.transactie_soort_id')
        ->where('transactie_soorten.naam', 'Winkeldiefstal')
        ->where('transactie_soort_sjabloon.type', 'rol')
        ->whereIn('transactie_soort_sjabloon.sjabloon_uri', [
            'http://ontologie.politie.nl/def/vwm#Rol_Aangever',
            'http://ontologie.politie.nl/def/vwm#Rol_Verdachte',
            'http://ontologie.politie.nl/def/vwm#Rol_Getuige',
        ])
        ->orderBy('transactie_soort_sjabloon.volgorde')
        ->get(['transactie_soort_sjabloon.sjabloon_uri', 'transactie_soort_sjabloon.crud_flags']);

    expect($rollen->map(fn ($rol) => [$rol->sjabloon_uri, $rol->crud_flags])->all())->toBe([
        ['http://ontologie.politie.nl/def/vwm#Rol_Aangever', 'CRD'],
        ['http://ontologie.politie.nl/def/vwm#Rol_Verdachte', 'CRD'],
        ['http://ontologie.politie.nl/def/vwm#Rol_Getuige', 'CRD'],
    ]);
});
