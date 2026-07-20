<?php

use App\Models\User;
use App\Services\AutoRoleMutationService;
use App\Services\GraphService;
use App\Services\ObjectMutationCommitService;
use App\Services\RoleMutationService;
use App\Services\RoleMutationWriter;
use App\Services\SjabloonMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('links an invalidating mutation to the state it logically removes', function () {
    $user = User::factory()->create();
    $now = now();

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'TEST-MUTATION-COMMIT',
        'omschrijving' => 'Test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Test case soort',
        'code' => 'TEST-MUTATION-COMMIT',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Muteren',
        'rdf_uri' => 'http://example.test/transactie/mutation-commit',
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
    $goicUri = 'http://example.test/goic/mutation-target';
    $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $goicUri,
        'dossier_id' => $dossierId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $tbUri = 'http://example.test/tb/mutation-target';
    $tbClass = 'http://example.test/MutationTargetDescription';
    $newTbClass = 'http://example.test/NewMutationTargetDescription';
    $tbId = DB::table('toestands_beschrijvingen')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $tbUri,
        'beschrijving' => $tbClass,
        'toestand_data' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchPropertyValueHintsByTbClasses')->once()->with([$newTbClass])->andReturn([]);
    $metadataService->shouldReceive('fetchIdentityRulesByTbClasses')->once()->with([$newTbClass])->andReturn([]);
    $metadataService->shouldReceive('fetchRolTbMetaByClasses')->once()->with([])->andReturn([]);

    $roleMutationService = new RoleMutationService($metadataService);
    $autoRoleMutationService = Mockery::mock(AutoRoleMutationService::class);
    $autoRoleMutationService->shouldReceive('appendAutoRoleItems')->once()->andReturn([]);

    $graphUpdate = null;
    $graphService = Mockery::mock(GraphService::class);
    $graphService
        ->shouldReceive('update')
        ->once()
        ->with(Mockery::on(function (string $sparql) use (&$graphUpdate, $tbUri): bool {
            $graphUpdate = $sparql;

            return str_contains($sparql, '<'.$tbUri.'> <http://ontologie.politie.nl/def/dpm#invalidatedAtTime>')
                && str_contains($sparql, '<http://ontologie.politie.nl/def/vwm#verwijdertLogisch> <'.$tbUri.'>');
        }));
    $graphService
        ->shouldReceive('validateShacl')
        ->once()
        ->andReturn(['conforms' => true, 'report' => '']);

    $service = new ObjectMutationCommitService(
        $graphService,
        $metadataService,
        $roleMutationService,
        new RoleMutationWriter,
        $autoRoleMutationService,
    );
    $dossier = DB::table('dossiers')->where('id', $dossierId)->first();

    $result = $service->commit(
        [
            'case_id' => $caseId,
            'transactie_soort_id' => $transactieSoortId,
        ],
        [[
            'client_id' => 'replacement',
            'sjabloon_uri' => $newTbClass,
            'target_class' => 'http://example.test/Person',
            'existing_goic_id' => $goicId,
            'data' => [],
        ]],
        [],
        [],
        [],
        [],
        false,
        $dossier,
        $user->id,
        'mutate',
        (object) [
            'tb_class' => $tbClass,
            'tb_uri' => $tbUri,
            'tb_id' => $tbId,
            'goic_id' => $goicId,
            'goic_uri' => $goicUri,
        ],
        [$goicId => 'http://example.test/Person'],
        [$newTbClass],
        [],
    );

    $newTbUri = $result['payload']['object_uris'][0];
    $invalidationMutation = DB::table('object_mutaties')->where('object_uri', $tbUri)->first(['rdf_uri']);
    $productionMutation = DB::table('object_mutaties')->where('object_uri', $newTbUri)->first(['rdf_uri']);

    expect($result['status'])->toBe(200)
        ->and($result['payload']['status'])->toBe('success')
        ->and($invalidationMutation?->rdf_uri)->toBeString()
        ->and($productionMutation?->rdf_uri)->toBeString()
        ->and($invalidationMutation?->rdf_uri)->not->toBe($productionMutation?->rdf_uri)
        ->and($graphUpdate)->toContain('<'.$invalidationMutation?->rdf_uri.'> <http://ontologie.politie.nl/def/vwm#verwijdertLogisch> <'.$tbUri.'>')
        ->and($graphUpdate)->toContain('<'.$productionMutation?->rdf_uri.'> <http://ontologie.politie.nl/def/vwm#produceert> <'.$newTbUri.'>');
});
