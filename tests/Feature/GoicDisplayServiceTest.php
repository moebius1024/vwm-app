<?php

use App\Models\User;
use App\Services\GoicDisplayService;
use App\Services\GraphService;
use App\Services\SjabloonMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createGoicDisplayCase(User $user): object
{
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'GOIC display rechtsgrond',
        'code' => 'GOIC-DISPLAY-RG',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'GOIC display case soort',
        'code' => 'GOIC-DISPLAY-CS',
        'rechtsgrond_id' => $rechtsgrondId,
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
        'rdf_uri' => 'http://example.test/dossier/'.Str::uuid(),
        'case_id' => $caseId,
        'parent_id' => null,
        'naam' => 'Hoofddossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (object) [
        'case_id' => $caseId,
        'dossier_id' => $dossierId,
    ];
}

/**
 * @param  array<int, array{tb_class:string,described_class:string,properties:array<int, string>,primary_display_property?:string|null,primary_display_label?:string|null}>  $identifiers
 * @param  array<string, string>  $labels
 */
function goicDisplayMetadata(array $identifiers, array $labels): SjabloonMetadataService
{
    $metadata = Mockery::mock(SjabloonMetadataService::class);
    $metadata->shouldReceive('listIdentifiers')->once()->andReturn($identifiers);
    $metadata->shouldReceive('listLabels')->once()->andReturn($labels);

    return $metadata;
}

test('goic display endpoint requires at least one uri', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->postJson('/api/goic/displays', ['uris' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uris');
});

test('it resolves a local goic display label from recent object mutation data', function () {
    $user = User::factory()->create();
    $case = createGoicDisplayCase($user);
    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/'.Str::uuid();
    $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $goicUri,
        'dossier_id' => $case->dossier_id,
        'context_data' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'GOIC display transactie',
        'rdf_uri' => 'http://example.test/transactie/goic-display',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $case->case_id,
        'transactie_soort_id' => $transactieSoortId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('object_mutaties')->insert([
        'transactie_id' => $transactieId,
        'sjabloon_uri' => 'http://example.test/Vehicle',
        'data' => json_encode([
            'http://ontologie.politie.nl/def/dpm#licensePlate' => ' PN-069-R ',
        ]),
        'object_uri' => null,
        'gegevens_object_in_context_id' => $goicId,
        'geproduceerde_toestand_id' => null,
        'verwijderde_toestand_id' => null,
        'datum_tijd' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldNotReceive('query');
    $metadata = goicDisplayMetadata([
        [
            'tb_class' => 'http://example.test/Vehicle',
            'described_class' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
            'properties' => [
                'http://ontologie.politie.nl/def/dpm#licensePlate',
                'http://ontologie.politie.nl/def/dpm#licensePlate',
            ],
            'primary_display_property' => 'http://ontologie.politie.nl/def/dpm#licensePlate',
            'primary_display_label' => 'Kenteken',
        ],
    ], ['http://ontologie.politie.nl/def/dpm#Vehicle' => 'Voertuig']);

    expect((new GoicDisplayService($graphService, $metadata))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'Kenteken PN-069-R']);
});

test('it falls back to graphdb labels for goics outside the users local cases', function () {
    $user = User::factory()->create();
    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/external-vehicle';
    $graphService = Mockery::mock(GraphService::class);
    $metadata = goicDisplayMetadata([
        [
            'tb_class' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving',
            'described_class' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
            'properties' => ['http://ontologie.politie.nl/def/dpm#licensePlate'],
            'primary_display_property' => 'http://ontologie.politie.nl/def/dpm#licensePlate',
            'primary_display_label' => 'Kenteken',
        ],
    ], ['http://ontologie.politie.nl/def/dpm#Vehicle' => 'Voertuig']);

    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, "<{$goicUri}>")
            && str_contains($query, 'vwm:heeftDoelClass')))
        ->andReturn([
            [
                'tb' => 'http://vwm.voorbeeld.nl/data/tb/vehicle',
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
                'targetClassLabel' => 'Voertuig',
                'property' => 'http://ontologie.politie.nl/def/dpm#licensePlate',
                'value' => 'AB-123-C',
            ],
        ]);

    expect((new GoicDisplayService($graphService, $metadata))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'Kenteken AB-123-C']);
});

test('it resolves an incident reference to a readable location label', function () {
    $user = User::factory()->create();
    $case = createGoicDisplayCase($user);
    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/'.Str::uuid();
    DB::table('gegevens_objecten_in_context')->insert([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $goicUri,
        'dossier_id' => $case->dossier_id,
        'context_data' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $graphService = Mockery::mock(GraphService::class);
    $metadata = goicDisplayMetadata([
        [
            'tb_class' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving',
            'described_class' => 'http://ontologie.politie.nl/def/dpm#Incident',
            'properties' => [
                'http://ontologie.politie.nl/def/dpm#location',
                'http://ontologie.politie.nl/def/dpm#timestamp',
            ],
            'primary_display_properties' => [
                ['property' => 'http://ontologie.politie.nl/def/dpm#location', 'label' => 'Locatie'],
                ['property' => 'http://ontologie.politie.nl/def/dpm#timestamp', 'label' => 'Tijd'],
            ],
        ],
    ], ['http://ontologie.politie.nl/def/dpm#Incident' => 'Incident']);
    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, "<{$goicUri}>") && str_contains($query, 'vwm:heeftDoelClass')))
        ->andReturn([
            [
                'tb' => 'http://vwm.voorbeeld.nl/data/tb/incident',
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Incident',
                'targetClassLabel' => 'Incident',
                'property' => 'http://ontologie.politie.nl/def/dpm#location',
                'value' => 'Groenekan',
            ],
            [
                'tb' => 'http://vwm.voorbeeld.nl/data/tb/incident',
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Incident',
                'targetClassLabel' => 'Incident',
                'property' => 'http://ontologie.politie.nl/def/dpm#timestamp',
                'value' => '2026-08-01T14:30:00+02:00',
            ],
        ]);

    expect((new GoicDisplayService($graphService, $metadata))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'Locatie Groenekan Tijd 2026-08-01T14:30:00+02:00']);
});

test('it resolves an enterprise reference from generic identifier metadata', function () {
    $user = User::factory()->create();
    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/enterprise';
    $graphService = Mockery::mock(GraphService::class);
    $metadata = goicDisplayMetadata([
        [
            'tb_class' => 'http://ontologie.politie.nl/def/vwm#OndernemingBeschrijving',
            'described_class' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
            'properties' => ['http://ontologie.politie.nl/def/dpm#naam'],
            'primary_display_property' => 'http://ontologie.politie.nl/def/dpm#naam',
            'primary_display_label' => 'Naam',
        ],
    ], ['http://ontologie.politie.nl/def/dpm#Onderneming' => 'Onderneming']);

    $graphService
        ->shouldReceive('query')
        ->once()
        ->andReturn([
            [
                'tb' => 'http://vwm.voorbeeld.nl/data/tb/enterprise',
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#OndernemingBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
                'targetClassLabel' => 'Onderneming',
                'property' => 'http://ontologie.politie.nl/def/dpm#naam',
                'value' => 'Mantel',
            ],
        ]);

    expect((new GoicDisplayService($graphService, $metadata))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'Naam Mantel']);
});

test('it uses the configured primary display identifier instead of another identifier', function () {
    $user = User::factory()->create();
    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/person';
    $graphService = Mockery::mock(GraphService::class);
    $metadata = goicDisplayMetadata([
        [
            'tb_class' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
            'described_class' => 'http://ontologie.politie.nl/def/dpm#Person',
            'properties' => [
                'http://ontologie.politie.nl/def/dpm#lastName',
                'http://ontologie.politie.nl/def/dpm#bsn',
            ],
            'primary_display_property' => 'http://ontologie.politie.nl/def/dpm#bsn',
            'primary_display_label' => 'BSN',
        ],
    ], ['http://ontologie.politie.nl/def/dpm#Person' => 'Persoon']);

    $graphService
        ->shouldReceive('query')
        ->once()
        ->andReturn([
            [
                'tb' => 'http://vwm.voorbeeld.nl/data/tb/person',
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person',
                'targetClassLabel' => 'Persoon',
                'property' => 'http://ontologie.politie.nl/def/dpm#lastName',
                'value' => 'Stolk',
            ],
            [
                'tb' => 'http://vwm.voorbeeld.nl/data/tb/person',
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person',
                'targetClassLabel' => 'Persoon',
                'property' => 'http://ontologie.politie.nl/def/dpm#bsn',
                'value' => '12345678',
            ],
        ]);

    expect((new GoicDisplayService($graphService, $metadata))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'BSN 12345678']);
});
