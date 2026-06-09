<?php

use App\Models\User;
use App\Services\GraphService;
use App\Services\SjabloonMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('deletes a role state through the mutation endpoint', function () {
    $user = User::factory()->create();
    $now = now();

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'TEST-DELETE',
        'omschrijving' => 'Test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-DELETE',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Muteren',
        'rdf_uri' => 'http://example.test/transactie/delete-role',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Dossier',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/goic/role-target',
        'dossier_id' => $dossierId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $roleTbClass = 'http://example.test/PersoonVoertuigRol';
    $roleType = 'http://example.test/RolType_Eigenaar';
    $tbUri = 'http://example.test/tb/role-1';

    DB::table('transactie_soort_sjabloon')->insert([
        'transactie_soort_id' => $transactieSoortId,
        'sjabloon_uri' => $roleTbClass,
        'type' => 'rol',
        'crud_flags' => 'D',
        'volgorde' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $toestandId = DB::table('toestands_beschrijvingen')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $tbUri,
        'beschrijving' => $roleTbClass,
        'toestand_data' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $sourceTransactieId = DB::table('transacties')->insertGetId([
        'case_id' => $caseId,
        'transactie_soort_id' => $transactieSoortId,
        'user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $sourceMutatieId = DB::table('object_mutaties')->insertGetId([
        'transactie_id' => $sourceTransactieId,
        'sjabloon_uri' => $roleTbClass,
        'object_uri' => $tbUri,
        'gegevens_object_in_context_id' => $goicId,
        'geproduceerde_toestand_id' => $toestandId,
        'datum_tijd' => $now,
        'data' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchRoleShapeRules')->andReturn([
        $roleType => ['rolTbClass' => $roleTbClass],
    ]);
    $this->instance(SjabloonMetadataService::class, $metadataService);

    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('update')
        ->once()
        ->with(Mockery::on(fn (string $sparql): bool => str_contains($sparql, $tbUri)
            && str_contains($sparql, 'invalidatedAtTime')));
    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/mutatie', [
            'transactie_soort_id' => $transactieSoortId,
            'case_id' => $caseId,
            'mode' => 'delete',
            'delete_type' => 'role',
            'target' => [
                'goic_id' => $goicId,
                'mutatie_id' => $sourceMutatieId,
                'tb_rdf_uri' => $tbUri,
                'sjabloon_uri' => $roleTbClass,
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('mode', 'delete')
        ->assertJsonPath('message', 'Rol verwijderd.');

    $deleteMutatie = DB::table('object_mutaties')
        ->where('transactie_id', '!=', $sourceTransactieId)
        ->where('object_uri', $tbUri)
        ->first();

    expect($deleteMutatie)->not->toBeNull()
        ->and((int) $deleteMutatie->verwijderde_toestand_id)->toBe($toestandId)
        ->and(json_decode((string) $deleteMutatie->data, true)['actie'] ?? null)->toBe('beeindig_toestand');
});
