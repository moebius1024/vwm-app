<?php

use App\Models\User;
use App\Services\GraphService;
use App\Services\MutationTargetResolver;
use App\Services\SjabloonMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('it rejects explicit role items instead of silently creating an empty transaction', function () {
    $user = User::factory()->create();

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'TEST',
        'omschrijving' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-TEST',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Registreren',
        'rdf_uri' => 'http://example.test/transactie/register',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Dossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $roleSelector = 'http://ontologie.politie.nl/def/vwm#Rol_Eigenaar';
    $roleType = 'http://ontologie.politie.nl/def/vwm#RolType_Eigenaar';
    $vehicleClass = 'http://ontologie.politie.nl/def/dpm#Vehicle';
    $personClass = 'http://ontologie.politie.nl/def/dpm#Person';
    $roleRule = [
        'rolType' => $roleType,
        'rolTbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonVoertuigRol',
        'vanClass' => $personClass,
        'naarClass' => $vehicleClass,
        'vanProperty' => 'http://ontologie.politie.nl/def/vwm#heeftPersoon',
        'naarProperty' => 'http://ontologie.politie.nl/def/vwm#heeftVoertuig',
    ];

    DB::table('transactie_soort_sjabloon')->insert([
        'transactie_soort_id' => $transactieSoortId,
        'sjabloon_uri' => $roleSelector,
        'type' => 'rol',
        'crud_flags' => 'CRD',
        'volgorde' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sourceGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/goic/source-vehicle',
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $targetGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/goic/target-vehicle',
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchRoleShapeRules')->andReturn([$roleType => $roleRule]);
    $metadataService->shouldReceive('fetchRolTypesByKey')->andReturn([]);
    $metadataService->shouldReceive('fetchDescribedClassByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchTbClassCapabilitiesByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchSubclassClosureMap')->andReturn([]);
    $metadataService->shouldReceive('fetchPropertyValueHintsByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchIdentityRulesByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchRolTbMetaByClasses')->with([$roleSelector])->andReturn([]);
    $metadataService->shouldReceive('fetchAutoRoleRules')->andReturn([]);
    $metadataService
        ->shouldReceive('resolveRoleShapeRuleFromSelector')
        ->with($roleSelector, Mockery::type('array'))
        ->andReturn($roleRule);
    $this->instance(SjabloonMetadataService::class, $metadataService);

    $resolver = Mockery::mock(MutationTargetResolver::class);
    $resolver
        ->shouldReceive('getGoicTargetClassMapForCase')
        ->with($caseId)
        ->andReturn([
            $sourceGoicId => $vehicleClass,
            $targetGoicId => $vehicleClass,
        ]);
    $this->instance(MutationTargetResolver::class, $resolver);

    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('update')->never();
    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/mutatie', [
            'transactie_soort_id' => $transactieSoortId,
            'case_id' => $caseId,
            'roles' => [
                'items' => [[
                    'roleTbClass' => $roleSelector,
                    'fromGoicId' => $sourceGoicId,
                    'toGoicId' => $targetGoicId,
                ]],
            ],
        ]);

    $expectedError = "Rol kan niet worden verwerkt: bronobject heeft class {$vehicleClass}, verwacht {$personClass}.";

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error', $expectedError);

    $errors = $response->json('errors');

    expect($errors)->toHaveKey('roles.items')
        ->and($errors['roles.items'][0] ?? null)->toBe($expectedError);

    expect(DB::table('transacties')->where('case_id', $caseId)->count())->toBe(0);
});

test('it rejects no-op mutations instead of committing an empty transaction', function () {
    $user = User::factory()->create();

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'TEST-NOOP',
        'omschrijving' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-NOOP',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Registreren',
        'rdf_uri' => 'http://example.test/transactie/register-noop',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Dossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $roleSelector = 'http://ontologie.politie.nl/def/vwm#Rol_Eigenaar';
    $roleType = 'http://ontologie.politie.nl/def/vwm#RolType_Eigenaar';
    $vehicleClass = 'http://ontologie.politie.nl/def/dpm#Vehicle';
    $personClass = 'http://ontologie.politie.nl/def/dpm#Person';
    $roleRule = [
        'rolType' => $roleType,
        'rolTbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonVoertuigRol',
        'vanClass' => $personClass,
        'naarClass' => $vehicleClass,
        'vanProperty' => 'http://ontologie.politie.nl/def/vwm#heeftPersoon',
        'naarProperty' => 'http://ontologie.politie.nl/def/vwm#heeftVoertuig',
    ];

    DB::table('transactie_soort_sjabloon')->insert([
        'transactie_soort_id' => $transactieSoortId,
        'sjabloon_uri' => $roleSelector,
        'type' => 'rol',
        'crud_flags' => 'CRD',
        'volgorde' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sourceGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/goic/source-vehicle-noop',
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchRoleShapeRules')->andReturn([$roleType => $roleRule]);
    $metadataService->shouldReceive('fetchRolTypesByKey')->andReturn([]);
    $metadataService->shouldReceive('fetchDescribedClassByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchTbClassCapabilitiesByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchSubclassClosureMap')->andReturn([]);
    $metadataService->shouldReceive('fetchPropertyValueHintsByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchIdentityRulesByTbClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchRolTbMetaByClasses')->with([$roleSelector])->andReturn([]);
    $metadataService->shouldReceive('fetchAutoRoleRules')->andReturn([]);
    $metadataService
        ->shouldReceive('resolveRoleShapeRuleFromSelector')
        ->with($roleSelector, Mockery::type('array'))
        ->andReturn($roleRule);
    $this->instance(SjabloonMetadataService::class, $metadataService);

    $resolver = Mockery::mock(MutationTargetResolver::class);
    $resolver
        ->shouldReceive('getGoicTargetClassMapForCase')
        ->with($caseId)
        ->andReturn([
            $sourceGoicId => $vehicleClass,
        ]);
    $this->instance(MutationTargetResolver::class, $resolver);

    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('update')->never();
    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/mutatie', [
            'transactie_soort_id' => $transactieSoortId,
            'case_id' => $caseId,
            'roles' => [
                'items' => [[
                    'roleTbClass' => $roleSelector,
                    'fromGoicId' => $sourceGoicId,
                    'isAuto' => true,
                ]],
            ],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error', 'Mutatie heeft geen inhoud opgeleverd.');

    expect(DB::table('transacties')->where('case_id', $caseId)->count())->toBe(0);
});
