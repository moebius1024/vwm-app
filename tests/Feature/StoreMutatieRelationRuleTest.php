<?php

use App\Models\User;
use App\Services\GraphService;
use App\Services\MutationTargetResolver;
use App\Services\SjabloonMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('it does not write generic relation rule triples when registering related objects', function () {
    $user = User::factory()->create();

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'REL-TEST',
        'omschrijving' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-REL',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Registreren',
        'rdf_uri' => 'http://example.test/transactie/register-relation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $incidentTbClass = 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving';
    $personTbClass = 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving';
    $incidentClass = 'http://ontologie.politie.nl/def/dpm#Incident';
    $personClass = 'http://ontologie.politie.nl/def/dpm#Person';

    foreach ([$incidentTbClass, $personTbClass] as $index => $tbClass) {
        DB::table('transactie_soort_sjabloon')->insert([
            'transactie_soort_id' => $transactieSoortId,
            'sjabloon_uri' => $tbClass,
            'type' => 'sjabloon',
            'crud_flags' => 'C',
            'volgorde' => $index + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('dossiers')->insert([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Dossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $metadataService->shouldReceive('fetchRoleShapeRules')->andReturn([]);
    $metadataService->shouldNotReceive('fetchRelatieRegels');
    $metadataService->shouldReceive('fetchRolTypesByKey')->andReturn([]);
    $metadataService->shouldReceive('fetchDescribedClassByTbClasses')
        ->with(Mockery::on(fn (array $classes) => empty(array_diff([$incidentTbClass, $personTbClass], $classes))))
        ->andReturn([
            $incidentTbClass => $incidentClass,
            $personTbClass => $personClass,
        ]);
    $metadataService->shouldReceive('fetchTbClassCapabilitiesByTbClasses')->andReturn([]);
    $metadataService->shouldReceive('fetchSubclassClosureMap')->andReturn([]);
    $metadataService->shouldReceive('fetchPropertyValueHintsByTbClasses')->andReturn([]);
    $metadataService->shouldReceive('fetchIdentityRulesByTbClasses')->andReturn([]);
    $metadataService->shouldReceive('fetchRolTbMetaByClasses')->with([])->andReturn([]);
    $metadataService->shouldReceive('fetchAutoRoleRules')->andReturn([]);
    $this->instance(SjabloonMetadataService::class, $metadataService);

    $resolver = Mockery::mock(MutationTargetResolver::class);
    $resolver->shouldReceive('getGoicTargetClassMapForCase')->with($caseId)->andReturn([]);
    $resolver->shouldReceive('tbClassCapabilityEnabled')->andReturn(false);
    $resolver->shouldReceive('resolveGoicIdsForTargetClass')->andReturn([]);
    $this->instance(MutationTargetResolver::class, $resolver);

    $capturedSparql = null;
    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('update')
        ->once()
        ->andReturnUsing(function (string $sparql) use (&$capturedSparql) {
            $capturedSparql = $sparql;

            return true;
        });
    $graphService->shouldReceive('validateShacl')->once()->andReturn([
        'conforms' => true,
        'report' => '',
    ]);
    $this->instance(GraphService::class, $graphService);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/mutatie', [
            'transactie_soort_id' => $transactieSoortId,
            'case_id' => $caseId,
            'objects' => [
                [
                    'client_id' => 'incident-1',
                    'sjabloon_uri' => $incidentTbClass,
                    'target_class' => $incidentClass,
                    'data' => [
                        'http://ontologie.politie.nl/def/dpm#location' => 'Rotterdam',
                    ],
                ],
                [
                    'client_id' => 'person-1',
                    'sjabloon_uri' => $personTbClass,
                    'target_class' => $personClass,
                    'data' => [
                        'http://ontologie.politie.nl/def/dpm#lastName' => 'Stolk',
                    ],
                ],
            ],
        ]);

    $response->assertSuccessful()->assertJsonPath('status', 'success');

    expect($capturedSparql)->not->toBeNull()
        ->and($capturedSparql)->toContain($incidentTbClass)
        ->and($capturedSparql)->toContain($personTbClass)
        ->and($capturedSparql)->not->toContain('http://ontologie.politie.nl/def/dpm#involvesPerson')
        ->and($capturedSparql)->not->toContain('http://ontologie.politie.nl/def/dpm#involvesVehicle');
});
