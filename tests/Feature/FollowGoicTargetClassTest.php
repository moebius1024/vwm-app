<?php

use App\Http\Controllers\CaseController;
use App\Models\User;
use App\Services\GraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createFollowGoicFixture(User $user): array
{
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'FOLLOW-GOIC',
        'omschrijving' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-FOLLOW',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Registreren',
        'rdf_uri' => 'http://example.test/transactie/follow-goic',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('case_soort_transactie')->insert([
        'case_soort_id' => $caseSoortId,
        'transactie_soort_id' => $transactieSoortId,
        'volgorde' => 1,
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

    $sourceGoicUri = 'http://example.test/goic/source-vehicle-'.((string) Str::uuid());
    DB::table('gegevens_objecten_in_context')->insert([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $sourceGoicUri,
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'case_id' => $caseId,
        'source_goic_uri' => $sourceGoicUri,
        'target_class' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
        'go_uri' => 'http://example.test/go/source-vehicle',
    ];
}

test('volg goic copies the source target class to the new goic', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, '<'.$fixture['source_goic_uri'].'> vwm:beschrijftGO ?go')
            && str_contains($query, '<'.$fixture['source_goic_uri'].'> vwm:heeftDoelClass ?targetClass')))
        ->andReturn([[
            'go' => $fixture['go_uri'],
            'targetClass' => $fixture['target_class'],
        ]])
        ->ordered();
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, 'DataObjectAssociation')
            && str_contains($query, $fixture['source_goic_uri'])))
        ->andReturn([])
        ->ordered();
    $graphService
        ->shouldReceive('update')
        ->once()
        ->with(Mockery::on(fn (string $sparql): bool => str_contains($sparql, '<http://ontologie.politie.nl/def/vwm#heeftDoelClass> <'.$fixture['target_class'].'>')
            && str_contains($sparql, '<http://ontologie.politie.nl/def/dpm#targetObject> <'.$fixture['source_goic_uri'].'>')));

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/volg', [
            'case_id' => $fixture['case_id'],
            'bron_goic_uri' => $fixture['source_goic_uri'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('target_class', $fixture['target_class']);

    expect(DB::table('data_object_associations')->where('target_goic_uri', $fixture['source_goic_uri'])->count())->toBe(1);

    $goicMutationData = DB::table('object_mutaties')
        ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#GegevensObjectInContext')
        ->value('data');

    expect(json_decode((string) $goicMutationData, true))->toMatchArray([
        'actie' => 'volg_goic',
        'bronGoic' => $fixture['source_goic_uri'],
        'doelClass' => $fixture['target_class'],
    ]);
});

test('volg goic rejects a source goic without target class', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, '<'.$fixture['source_goic_uri'].'> vwm:beschrijftGO ?go')))
        ->andReturn([[
            'go' => $fixture['go_uri'],
        ]]);
    $graphService->shouldReceive('update')->never();

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/volg', [
            'case_id' => $fixture['case_id'],
            'bron_goic_uri' => $fixture['source_goic_uri'],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'source_target_class_missing');

    expect(DB::table('transacties')->where('case_id', $fixture['case_id'])->count())->toBe(0)
        ->and(DB::table('data_object_associations')->count())->toBe(0);
});

test('volg goic rejects multiple source goic inputs with reason code', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('query')->never();
    $graphService->shouldReceive('update')->never();

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/volg', [
            'case_id' => $fixture['case_id'],
            'bron_goic_uri' => $fixture['source_goic_uri'],
            'bron_goic_uris' => [$fixture['source_goic_uri']],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'multiple_input_field');

    expect(DB::table('data_object_associations')->count())->toBe(0);
});

test('volg goic returns existing local goic when source is already followed', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $dossierId = DB::table('dossiers')
        ->where('case_id', $fixture['case_id'])
        ->value('id');
    $existingLocalGoicUri = 'http://example.test/goic/already-followed-'.((string) Str::uuid());
    $existingLocalGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $existingLocalGoicUri,
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $fixture['case_id'],
        'transactie_soort_id' => DB::table('transactie_soorten')->orderBy('id')->value('id'),
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $objectMutatieId = DB::table('object_mutaties')->insertGetId([
        'transactie_id' => $transactieId,
        'sjabloon_uri' => 'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
        'object_uri' => 'http://example.test/association/already-followed-mutatie-'.((string) Str::uuid()),
        'gegevens_object_in_context_id' => $existingLocalGoicId,
        'geproduceerde_toestand_id' => null,
        'datum_tijd' => now(),
        'data' => json_encode([
            'ownedObject' => $existingLocalGoicUri,
            'targetObject' => $fixture['source_goic_uri'],
        ], JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('data_object_associations')->insert([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/association/already-followed-'.((string) Str::uuid()),
        'object_mutatie_id' => $objectMutatieId,
        'owned_goic_uri' => $existingLocalGoicUri,
        'target_goic_uri' => $fixture['source_goic_uri'],
        'produced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, '<'.$fixture['source_goic_uri'].'> vwm:beschrijftGO ?go')))
        ->andReturn([[
            'go' => $fixture['go_uri'],
            'targetClass' => $fixture['target_class'],
        ]])
        ->ordered();
    $graphService->shouldReceive('update')->never();

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/volg', [
            'case_id' => $fixture['case_id'],
            'bron_goic_uri' => $fixture['source_goic_uri'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('already_exists', true)
        ->assertJsonPath('goic_id', $existingLocalGoicId)
        ->assertJsonPath('goic_uri', $existingLocalGoicUri);

    expect(DB::table('transacties')->where('case_id', $fixture['case_id'])->count())->toBe(1);
});

test('volg goic ignores invalidated local follow association', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $dossierId = DB::table('dossiers')
        ->where('case_id', $fixture['case_id'])
        ->value('id');
    $invalidatedLocalGoicUri = 'http://example.test/goic/invalidated-follow-'.((string) Str::uuid());
    $invalidatedLocalGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $invalidatedLocalGoicUri,
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $fixture['case_id'],
        'transactie_soort_id' => DB::table('transactie_soorten')->orderBy('id')->value('id'),
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $objectMutatieId = DB::table('object_mutaties')->insertGetId([
        'transactie_id' => $transactieId,
        'sjabloon_uri' => 'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
        'object_uri' => 'http://example.test/association/invalidated-mutatie-'.((string) Str::uuid()),
        'gegevens_object_in_context_id' => $invalidatedLocalGoicId,
        'geproduceerde_toestand_id' => null,
        'datum_tijd' => now(),
        'data' => json_encode([
            'ownedObject' => $invalidatedLocalGoicUri,
            'targetObject' => $fixture['source_goic_uri'],
            'invalidatedAtTime' => now()->toAtomString(),
        ], JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('data_object_associations')->insert([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/association/invalidated-'.((string) Str::uuid()),
        'object_mutatie_id' => $objectMutatieId,
        'owned_goic_uri' => $invalidatedLocalGoicUri,
        'target_goic_uri' => $fixture['source_goic_uri'],
        'produced_at' => now()->subMinute(),
        'invalidated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, '<'.$fixture['source_goic_uri'].'> vwm:beschrijftGO ?go')))
        ->andReturn([[
            'go' => $fixture['go_uri'],
            'targetClass' => $fixture['target_class'],
        ]])
        ->ordered();
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, 'DataObjectAssociation')
            && str_contains($query, 'FILTER NOT EXISTS')
            && str_contains($query, 'invalidatedAtTime')))
        ->andReturn([])
        ->ordered();
    $graphService
        ->shouldReceive('update')
        ->once()
        ->with(Mockery::on(fn (string $sparql): bool => str_contains($sparql, '<http://ontologie.politie.nl/def/dpm#targetObject> <'.$fixture['source_goic_uri'].'>')));

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/volg', [
            'case_id' => $fixture['case_id'],
            'bron_goic_uri' => $fixture['source_goic_uri'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('already_exists', null);

    expect(DB::table('data_object_associations')->where('target_goic_uri', $fixture['source_goic_uri'])->count())->toBe(2)
        ->and(DB::table('data_object_associations')->where('target_goic_uri', $fixture['source_goic_uri'])->whereNull('invalidated_at')->count())->toBe(1);
});

test('ontvolg goic invalidates the data object association', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $dossierId = DB::table('dossiers')
        ->where('case_id', $fixture['case_id'])
        ->value('id');
    $localGoicUri = 'http://example.test/goic/followed-'.((string) Str::uuid());
    $localGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $localGoicUri,
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $fixture['case_id'],
        'transactie_soort_id' => DB::table('transactie_soorten')->orderBy('id')->value('id'),
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $objectMutatieId = DB::table('object_mutaties')->insertGetId([
        'transactie_id' => $transactieId,
        'sjabloon_uri' => 'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
        'object_uri' => 'http://example.test/association/followed-mutatie-'.((string) Str::uuid()),
        'gegevens_object_in_context_id' => $localGoicId,
        'geproduceerde_toestand_id' => null,
        'datum_tijd' => now(),
        'data' => json_encode([
            'ownedObject' => $localGoicUri,
            'targetObject' => $fixture['source_goic_uri'],
        ], JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $associationUri = 'http://example.test/association/followed-'.((string) Str::uuid());
    DB::table('data_object_associations')->insert([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $associationUri,
        'object_mutatie_id' => $objectMutatieId,
        'owned_goic_uri' => $localGoicUri,
        'target_goic_uri' => $fixture['source_goic_uri'],
        'produced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('update')
        ->once()
        ->with(Mockery::on(fn (string $sparql): bool => str_contains($sparql, '<'.$associationUri.'> <http://ontologie.politie.nl/def/dpm#invalidatedAtTime>')
            && str_contains($sparql, '<http://ontologie.politie.nl/def/vwm#heeftBetrekkingOp> <'.$localGoicUri.'>')));

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/ontvolg', [
            'case_id' => $fixture['case_id'],
            'association_uri' => $associationUri,
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('association_uri', $associationUri)
        ->assertJsonPath('goic_id', $localGoicId)
        ->assertJsonPath('target_goic_uri', $fixture['source_goic_uri']);

    $association = DB::table('data_object_associations')
        ->where('rdf_uri', $associationUri)
        ->first(['invalidated_at']);

    $deleteMutationData = DB::table('object_mutaties')
        ->where('object_uri', $associationUri)
        ->orderByDesc('id')
        ->value('data');

    expect($association?->invalidated_at)->not->toBeNull()
        ->and($deleteMutationData)->toContain('beeindig_volg_goic');
});

test('ontvolg goic rejects invalid association uri values with reason code', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('update')->never();

    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/goic/ontvolg', [
            'case_id' => $fixture['case_id'],
            'association_uri' => 'urn:association:123',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_uri_format');

    expect(DB::table('data_object_associations')->count())->toBe(0);
});

test('go link count filter ignores goics with only invalidated follow association', function () {
    $user = User::factory()->create();
    $fixture = createFollowGoicFixture($user);

    $dossierId = DB::table('dossiers')
        ->where('case_id', $fixture['case_id'])
        ->value('id');
    $activeFollowGoicUri = 'http://example.test/goic/active-follow-'.((string) Str::uuid());
    $invalidatedFollowGoicUri = 'http://example.test/goic/invalidated-follow-'.((string) Str::uuid());

    $activeFollowGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $activeFollowGoicUri,
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $invalidatedFollowGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $invalidatedFollowGoicUri,
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $fixture['case_id'],
        'transactie_soort_id' => DB::table('transactie_soorten')->orderBy('id')->value('id'),
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        [$activeFollowGoicId, $activeFollowGoicUri, null],
        [$invalidatedFollowGoicId, $invalidatedFollowGoicUri, now()],
    ] as [$goicId, $goicUri, $invalidatedAt]) {
        $objectMutatieId = DB::table('object_mutaties')->insertGetId([
            'transactie_id' => $transactieId,
            'sjabloon_uri' => 'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
            'object_uri' => 'http://example.test/association/filter-mutatie-'.((string) Str::uuid()),
            'gegevens_object_in_context_id' => $goicId,
            'geproduceerde_toestand_id' => null,
            'datum_tijd' => now(),
            'data' => json_encode([
                'ownedObject' => $goicUri,
                'targetObject' => $fixture['source_goic_uri'],
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('data_object_associations')->insert([
            'uuid' => (string) Str::uuid(),
            'rdf_uri' => 'http://example.test/association/filter-'.((string) Str::uuid()),
            'object_mutatie_id' => $objectMutatieId,
            'owned_goic_uri' => $goicUri,
            'target_goic_uri' => $fixture['source_goic_uri'],
            'produced_at' => now(),
            'invalidated_at' => $invalidatedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, 'beschrijftGOIC')
            && str_contains($query, 'invalidatedAtTime')))
        ->andReturn([]);

    $this->instance(GraphService::class, $graphService);

    $controller = app(CaseController::class);
    $method = new ReflectionMethod($controller, 'filterActiveGoicUris');
    $method->setAccessible(true);

    $activeUris = $method->invoke($controller, [
        $activeFollowGoicUri,
        $invalidatedFollowGoicUri,
    ]);

    expect($activeUris)->toBe([$activeFollowGoicUri]);
});
