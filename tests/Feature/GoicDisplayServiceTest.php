<?php

use App\Models\User;
use App\Services\GoicDisplayService;
use App\Services\GraphService;
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

    expect((new GoicDisplayService($graphService))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'Voertuig: PN-069-R']);
});

test('it falls back to graphdb labels for goics outside the users local cases', function () {
    $user = User::factory()->create();
    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/external-vehicle';
    $graphService = Mockery::mock(GraphService::class);

    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, "<{$goicUri}>")
            && str_contains($query, 'dpm:licensePlate')))
        ->andReturn([
            ['plate' => 'AB-123-C', 'brand' => null, 'model' => null],
        ]);

    expect((new GoicDisplayService($graphService))->resolveLabels([$goicUri], $user->id))
        ->toBe([$goicUri => 'Voertuig: AB-123-C']);
});
