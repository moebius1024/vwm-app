<?php

use App\Services\RoleMutationService;
use App\Services\SjabloonMetadataService;

function makeRoleMutationService(?SjabloonMetadataService $metadataService = null): RoleMutationService
{
    return new RoleMutationService($metadataService ?? Mockery::mock(SjabloonMetadataService::class));
}

test('it checks crud flags case insensitively', function () {
    $service = makeRoleMutationService();

    expect($service->hasCrud('ruDa', 'd'))->toBeTrue()
        ->and($service->hasCrud('RU', 'D'))->toBeFalse()
        ->and($service->hasCrud(null, 'C'))->toBeTrue();
});

test('it recognizes role tb classes from shape rules', function () {
    $service = makeRoleMutationService();

    $roleShapeRules = [
        'http://example.test/Bestuurder' => [
            'rolTbClass' => 'http://example.test/BestuurderRol',
        ],
    ];

    expect($service->isRoleTbClass('http://example.test/BestuurderRol', $roleShapeRules))->toBeTrue()
        ->and($service->isRoleTbClass('http://example.test/AndereRol', $roleShapeRules))->toBeFalse()
        ->and($service->isRoleTbClass('', $roleShapeRules))->toBeFalse();
});

test('it allows direct role selections and metadata resolved selections', function () {
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $service = makeRoleMutationService($metadataService);

    $metadataService
        ->shouldReceive('resolveRoleShapeRuleFromSelector')
        ->once()
        ->with('http://example.test/BestuurderSelector', [])
        ->andReturn(['rolType' => 'http://example.test/Bestuurder']);

    expect($service->isAllowedRoleSelection(
        'http://example.test/DirectRole',
        null,
        ['http://example.test/DirectRole'],
        [],
    ))->toBeTrue()
        ->and($service->isAllowedRoleSelection(
            'http://example.test/Bestuurder',
            null,
            ['http://example.test/BestuurderSelector'],
            [],
        ))->toBeTrue();
});

test('it applies create permissions from role crud selectors', function () {
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $service = makeRoleMutationService($metadataService);

    $metadataService
        ->shouldReceive('resolveRoleShapeRuleFromSelector')
        ->once()
        ->with('http://example.test/BestuurderSelector', [])
        ->andReturn([
            'rolType' => 'http://example.test/Bestuurder',
            'rolTbClass' => 'http://example.test/BestuurderRol',
        ]);

    expect($service->isRoleCreateAllowed(
        'http://example.test/Bestuurder',
        null,
        ['http://example.test/BestuurderSelector' => 'CRD'],
        [],
    ))->toBeTrue()
        ->and($service->isRoleCreateAllowed(
            'http://example.test/Bestuurder',
            null,
            ['http://example.test/BestuurderSelector' => 'RD'],
            [],
        ))->toBeFalse()
        ->and($service->isRoleCreateAllowed(
            'http://example.test/Bestuurder',
            null,
            [],
            [],
        ))->toBeTrue();
});
