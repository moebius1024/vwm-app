<?php

use Illuminate\Support\Facades\DB;

it('configures the function role for Winkeldiefstal', function () {
    DB::table('transactie_soorten')->insert(['naam' => 'Winkeldiefstal', 'rdf_uri' => '', 'created_at' => now(), 'updated_at' => now()]);
    $migration = require base_path('database/migrations/2026_07_27_152521_add_functie_role_to_winkeldiefstal_transaction.php');
    $migration->up();
    $migration->up();
    $rol = DB::table('transactie_soort_sjabloon')->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#Rol_Functie')->where('type', 'rol')->first();
    expect($rol)->not->toBeNull()->and($rol->crud_flags)->toBe('CRD')->and($rol->volgorde)->toBe(4);
});
