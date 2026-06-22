<?php

use App\Services\AutoRoleMutationService;
use App\Services\MutationTargetResolver;
use App\Services\RoleMutationService;
use App\Services\SjabloonMetadataService;
use Tests\TestCase;

uses(TestCase::class);

function makeAutoRoleMutationService(
    ?SjabloonMetadataService $metadataService = null,
    ?RoleMutationService $roleMutationService = null,
    ?MutationTargetResolver $mutationTargetResolver = null,
): AutoRoleMutationService {
    return new AutoRoleMutationService(
        $metadataService ?? Mockery::mock(SjabloonMetadataService::class),
        $roleMutationService ?? Mockery::mock(RoleMutationService::class),
        $mutationTargetResolver ?? Mockery::mock(MutationTargetResolver::class),
    );
}

test('it appends matching auto role items once', function () {
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchAutoRoleRules')->twice()->andReturn([[
        'triggerTbClass' => 'http://example.test/PersoonSignalement',
        'rolType' => 'http://example.test/RolType_Verdachte',
    ]]);

    $service = makeAutoRoleMutationService($metadataService);

    $result = $service->appendAutoRoleItems(
        roleItems: [],
        objects: [[
            'client_id' => 'persoon-1',
            'sjabloon_uri' => 'http://example.test/PersoonSignalement',
        ]],
        objectMeta: [[
            'client_id' => 'persoon-1',
            'goic_id' => 10,
            'target_class' => 'http://example.test/Person',
        ]],
        goicByClass: [
            'http://example.test/Incident' => ['http://example.test/goic/incident-1'],
        ],
        roleShapeRules: [
            'http://example.test/RolType_Verdachte' => [
                'vanClass' => 'http://example.test/Person',
                'naarClass' => 'http://example.test/Incident',
            ],
        ],
    );

    expect($result)->toBe([[
        'roleType' => 'http://example.test/RolType_Verdachte',
        'fromGoicId' => 10,
        'toId' => null,
        'toGoicId' => null,
        'toUri' => 'http://example.test/goic/incident-1',
        'isAuto' => true,
    ]]);

    $deduplicated = $service->appendAutoRoleItems(
        roleItems: $result,
        objects: [[
            'client_id' => 'persoon-1',
            'sjabloon_uri' => 'http://example.test/PersoonSignalement',
        ]],
        objectMeta: [[
            'client_id' => 'persoon-1',
            'goic_id' => 10,
            'target_class' => 'http://example.test/Person',
        ]],
        goicByClass: [
            'http://example.test/Incident' => ['http://example.test/goic/incident-1'],
        ],
        roleShapeRules: [
            'http://example.test/RolType_Verdachte' => [
                'vanClass' => 'http://example.test/Person',
                'naarClass' => 'http://example.test/Incident',
            ],
        ],
    );

    expect($deduplicated)->toBe($result);
});

test('it collects rule based invalidation uris', function () {
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchAutoRoleInvalidationRules')->once()->andReturn([[
        'triggerTbClass' => 'http://example.test/PersoonSignalement',
        'rolType' => 'http://example.test/RolType_Verdachte',
    ]]);

    $service = makeAutoRoleMutationService($metadataService);

    $result = $service->collectRuleBasedInvalidationUris(
        deletedTbClass: 'http://example.test/PersoonSignalement',
        sourceGoicUri: 'http://example.test/goic/person-1',
        roleShapeRules: [
            'http://example.test/RolType_Verdachte' => [
                'rolTbClass' => 'http://example.test/PersoonIncidentRol',
                'vanProperty' => 'http://example.test/heeftPersoon',
            ],
        ],
        activeRoleUriResolver: fn (string $sourceGoicUri, string $roleTbClass, string $rolType, string $fromProperty): array => [
            $sourceGoicUri.'|'.$roleTbClass.'|'.$rolType.'|'.$fromProperty,
        ],
    );

    expect($result)->toBe([
        'http://example.test/goic/person-1|http://example.test/PersoonIncidentRol|http://example.test/RolType_Verdachte|http://example.test/heeftPersoon' => 'http://example.test/PersoonIncidentRol',
    ]);
});

test('it cascades dependent states when no kernel remains', function () {
    $roleMutationService = Mockery::mock(RoleMutationService::class);
    $roleMutationService->shouldReceive('isRoleTbClass')->andReturnUsing(fn (string $tbClass): bool => $tbClass === 'http://example.test/RolTb');

    $mutationTargetResolver = Mockery::mock(MutationTargetResolver::class);
    $mutationTargetResolver->shouldReceive('tbClassCapabilityEnabled')->andReturnUsing(
        fn (string $tbClass, array $capabilities, string $capability): bool => (bool) ($capabilities[$tbClass][$capability] ?? false)
    );

    $service = makeAutoRoleMutationService(
        roleMutationService: $roleMutationService,
        mutationTargetResolver: $mutationTargetResolver,
    );

    $rows = [
        ['tb_uri' => 'role', 'tb_class' => 'http://example.test/RolTb'],
        ['tb_uri' => 'projection', 'tb_class' => 'http://example.test/ProjectieTb'],
        ['tb_uri' => 'contact', 'tb_class' => 'http://example.test/ContactGegevens'],
        ['tb_uri' => 'association', 'tb_class' => 'http://example.test/DataObjectAssociation'],
    ];

    expect($service->collectCascadeRowsWhenNoKernelRemains($rows, [], [
        'http://example.test/ProjectieTb' => ['is_state_projection' => true],
    ]))->toBe([
        ['tb_uri' => 'role', 'tb_class' => 'http://example.test/RolTb'],
        ['tb_uri' => 'projection', 'tb_class' => 'http://example.test/ProjectieTb'],
        ['tb_uri' => 'contact', 'tb_class' => 'http://example.test/ContactGegevens'],
    ]);

    expect($service->collectCascadeRowsWhenNoKernelRemains([...$rows, [
        'tb_uri' => 'kernel',
        'tb_class' => 'http://example.test/SignalementTb',
    ]], [], [
        'http://example.test/ProjectieTb' => ['is_state_projection' => true],
        'http://example.test/SignalementTb' => ['is_signalement' => true],
    ]))->toBe([]);
});
