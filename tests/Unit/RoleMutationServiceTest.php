<?php

use App\Services\RoleMutationService;
use App\Services\SjabloonMetadataService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

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

test('it normalizes role items from explicit and legacy role payloads', function () {
    $service = makeRoleMutationService();

    $items = $service->normalizeRoleItems([
        'items' => [[
            'roleType' => 'http://example.test/Expliciet',
            'fromGoicId' => 10,
            'toGoicId' => 11,
        ]],
        'bestuurder' => [[
            'voertuigId' => 'obj_voertuig',
            'persoonId' => 'obj_persoon',
        ]],
        'onbekend' => [[
            'fromId' => 'obj_x',
            'toId' => 'obj_y',
        ]],
    ], [
        'bestuurder' => 'http://example.test/Bestuurder',
    ]);

    expect($items)->toBe([
        [
            'roleType' => 'http://example.test/Expliciet',
            'fromGoicId' => 10,
            'toGoicId' => 11,
        ],
        [
            'roleType' => 'http://example.test/Bestuurder',
            'fromId' => 'obj_voertuig',
            'toId' => 'obj_persoon',
        ],
    ]);
});

test('it collects role tb classes from normalized items', function () {
    $service = makeRoleMutationService();

    expect($service->collectRoleTbClasses([
        ['roleTbClass' => 'http://example.test/RolA'],
        ['roleType' => 'http://example.test/RolType'],
        ['roleTbClass' => 'http://example.test/RolB'],
        'geen-array',
    ]))->toBe([
        'http://example.test/RolA',
        'http://example.test/RolB',
    ]);
});

test('it builds client maps from object metadata', function () {
    $service = makeRoleMutationService();

    expect($service->buildClientMap([
        ['client_id' => 'obj_1', 'goic_id' => 1],
        ['goic_id' => 2],
        'geen-array',
        ['client_id' => 'obj_3', 'goic_id' => 3],
    ]))->toBe([
        'obj_1' => ['client_id' => 'obj_1', 'goic_id' => 1],
        'obj_3' => ['client_id' => 'obj_3', 'goic_id' => 3],
    ]);
});

test('it builds role mutation plans for explicit existing goic roles', function () {
    $service = makeRoleMutationService();

    $plans = $service->buildRoleMutationPlans(
        roleItems: [[
            'roleType' => 'http://example.test/RolType_Eigenaar',
            'fromGoicId' => 10,
            'toGoicId' => 20,
        ]],
        rolTbMetaByClass: [],
        roleShapeRules: [
            'http://example.test/RolType_Eigenaar' => [
                'rolTbClass' => 'http://example.test/PersoonVoertuigRol',
                'vanClass' => 'http://example.test/Person',
                'naarClass' => 'http://example.test/Vehicle',
                'vanProperty' => 'http://example.test/heeftPersoon',
                'naarProperty' => 'http://example.test/heeftVoertuig',
            ],
        ],
        allowedRoleSelectors: [],
        roleCrudBySelector: [],
        enforceAllowedRole: false,
        goicMetaById: [
            10 => [
                'goic_id' => 10,
                'goic_uri' => 'http://example.test/goic/person',
                'target_class' => 'http://example.test/Person',
            ],
            20 => [
                'goic_id' => 20,
                'goic_uri' => 'http://example.test/goic/vehicle',
                'target_class' => 'http://example.test/Vehicle',
            ],
        ],
        clientMap: [],
        goicByClass: [],
    );

    expect($plans)->toBe([[
        'role_type' => 'http://example.test/RolType_Eigenaar',
        'role_tb_class' => 'http://example.test/PersoonVoertuigRol',
        'from_goic_id' => 10,
        'from_goic_uri' => 'http://example.test/goic/person',
        'to_goic_uri' => 'http://example.test/goic/vehicle',
        'van_property' => 'http://example.test/heeftPersoon',
        'naar_property' => 'http://example.test/heeftVoertuig',
    ]]);
});

test('it rejects explicit role plans with an unexpected source class', function () {
    $service = makeRoleMutationService();

    expect(fn () => $service->buildRoleMutationPlans(
        roleItems: [[
            'roleType' => 'http://example.test/RolType_Eigenaar',
            'fromGoicId' => 10,
            'toGoicId' => 20,
        ]],
        rolTbMetaByClass: [],
        roleShapeRules: [
            'http://example.test/RolType_Eigenaar' => [
                'rolTbClass' => 'http://example.test/PersoonVoertuigRol',
                'vanClass' => 'http://example.test/Person',
                'naarClass' => 'http://example.test/Vehicle',
                'vanProperty' => 'http://example.test/heeftPersoon',
                'naarProperty' => 'http://example.test/heeftVoertuig',
            ],
        ],
        allowedRoleSelectors: [],
        roleCrudBySelector: [],
        enforceAllowedRole: false,
        goicMetaById: [
            10 => [
                'goic_id' => 10,
                'goic_uri' => 'http://example.test/goic/vehicle-a',
                'target_class' => 'http://example.test/Vehicle',
            ],
            20 => [
                'goic_id' => 20,
                'goic_uri' => 'http://example.test/goic/vehicle-b',
                'target_class' => 'http://example.test/Vehicle',
            ],
        ],
        clientMap: [],
        goicByClass: [],
    ))->toThrow(ValidationException::class, 'Rol kan niet worden verwerkt: bronobject heeft class http://example.test/Vehicle, verwacht http://example.test/Person.');
});
