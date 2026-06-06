<?php

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
