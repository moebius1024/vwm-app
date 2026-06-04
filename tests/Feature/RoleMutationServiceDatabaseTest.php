<?php

use App\Services\RoleMutationService;
use Illuminate\Support\Facades\DB;

function createRoleMutationTransactieSoort(): int
{
    return DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Test transactie',
        'rdf_uri' => 'http://example.test/transactie/test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('it reads allowed role configuration from transactie soort sjablonen', function () {
    $transactieSoortId = createRoleMutationTransactieSoort();

    DB::table('transactie_soort_sjabloon')->insert([
        [
            'transactie_soort_id' => $transactieSoortId,
            'sjabloon_uri' => 'http://example.test/RolA',
            'type' => 'rol',
            'crud_flags' => 'cr',
            'volgorde' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'transactie_soort_id' => $transactieSoortId,
            'sjabloon_uri' => 'http://example.test/RolB',
            'type' => 'rol',
            'crud_flags' => 'D',
            'volgorde' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $configuration = app(RoleMutationService::class)->fetchAllowedRoleConfiguration($transactieSoortId);

    expect($configuration)->toBe([
        'allowed_selectors' => [
            'http://example.test/RolB',
            'http://example.test/RolA',
        ],
        'crud_by_selector' => [
            'http://example.test/RolB' => 'D',
            'http://example.test/RolA' => 'CR',
        ],
        'enforce_allowed' => true,
    ]);
});

test('it reads sjabloon crud flags and direct role delete permissions', function () {
    $transactieSoortId = createRoleMutationTransactieSoort();

    DB::table('transactie_soort_sjabloon')->insert([
        [
            'transactie_soort_id' => $transactieSoortId,
            'sjabloon_uri' => 'http://example.test/Persoon',
            'type' => 'sjabloon',
            'crud_flags' => 'ruda',
            'volgorde' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'transactie_soort_id' => $transactieSoortId,
            'sjabloon_uri' => 'http://example.test/BestuurderRol',
            'type' => 'rol',
            'crud_flags' => 'RD',
            'volgorde' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $service = app(RoleMutationService::class);

    expect($service->fetchAllowedSjabloonCrudByTbClass($transactieSoortId))->toBe([
        'http://example.test/Persoon' => 'RUDA',
    ])
        ->and($service->isRoleDeleteAllowed(
            $transactieSoortId,
            'http://example.test/BestuurderRol',
            [],
        ))->toBeTrue();
});
