<?php

use App\Models\User;
use App\Services\GraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('creates an auditable dependent state for an eligible state in another case', function () {
    $user = User::factory()->create();
    $transactionTypeId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Volgen toestand',
        'rdf_uri' => 'http://example.test/transaction/follow-state',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Volgen toestand',
        'code' => 'DST-'.Str::uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    dependentStateAuthorization($user->id, $rechtsgrondId);
    $target = dependentStateGoic($user->id, $transactionTypeId, $rechtsgrondId, 'Doel');
    $source = dependentStateGoic($user->id, $transactionTypeId, $rechtsgrondId, 'Bron');
    $sourceTbUri = 'http://vwm.voorbeeld.nl/data/tb/source';
    $goUri = 'http://vwm.voorbeeld.nl/data/go/shared';

    $graph = Mockery::mock(GraphService::class);
    $graph->shouldReceive('query')->andReturnUsing(function (string $query) use ($source, $sourceTbUri): array {
        if (str_contains($query, "<{$sourceTbUri}>") && str_contains($query, 'SELECT ?sourceGoic')) {
            return [[
                'sourceGoic' => $source['rdf_uri'],
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#ContactGegevens',
            ]];
        }
        if (str_contains($query, 'SELECT ?child ?parent')) {
            return [['child' => 'http://ontologie.politie.nl/def/vwm#ContactGegevens', 'parent' => 'http://ontologie.politie.nl/def/vwm#ToestandsBeschrijving']];
        }
        if (str_contains($query, 'SELECT ?tbClass ?searchProperty')) {
            return [];
        }
        if (str_contains($query, 'SELECT ?dependent')) {
            return [];
        }

        return [];
    });
    $graph->shouldReceive('update')->once()->with(Mockery::on(function (string $sparql) use ($target, $sourceTbUri): bool {
        return str_contains($sparql, (string) $target['rdf_uri'])
            && str_contains($sparql, "<{$sourceTbUri}>")
            && str_contains($sparql, 'AfhankelijkeTB');
    }));
    $graph->shouldReceive('validateShacl')->once()->andReturn(['conforms' => true, 'report' => '']);
    $this->instance(GraphService::class, $graph);

    $this->actingAs($user)
        ->postJson(route('api.toestand.follow.store'), [
            'case_id' => $target['case_id'],
            'target_goic_id' => $target['id'],
            'source_tb_uri' => $sourceTbUri,
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'De beschrijving wordt nu gevolgd.');

    $mutation = DB::table('object_mutaties')->latest('id')->first(['sjabloon_uri', 'gegevens_object_in_context_id', 'data']);
    expect($mutation->sjabloon_uri)->toBe('http://ontologie.politie.nl/def/vwm#AfhankelijkeTB')
        ->and((int) $mutation->gegevens_object_in_context_id)->toBe($target['id'])
        ->and(json_decode((string) $mutation->data, true))->toMatchArray([
            'actie' => 'volg_toestand',
            'http://ontologie.politie.nl/def/vwm#verwijstNaar' => $sourceTbUri,
        ]);
});

/** @return array{id:int,case_id:int,rdf_uri:string} */
function dependentStateGoic(int $userId, int $transactionTypeId, int $rechtsgrondId, string $name): array
{
    $now = now();
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => $name,
        'code' => 'DST-'.Str::uuid(),
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('case_soort_transactie')->insert([
        'case_soort_id' => $caseSoortId,
        'transactie_soort_id' => $transactionTypeId,
        'volgorde' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://vwm.voorbeeld.nl/data/dossier/'.Str::uuid(),
        'case_id' => $caseId,
        'naam' => "Dossier {$name}",
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $rdfUri = 'http://vwm.voorbeeld.nl/data/goic/'.Str::uuid();
    $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $rdfUri,
        'dossier_id' => $dossierId,
        'context_data' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $goicId, 'case_id' => $caseId, 'rdf_uri' => $rdfUri];
}

function dependentStateAuthorization(int $userId, int $rechtsgrondId): void
{
    $now = now();
    $teamId = DB::table('teams')->insertGetId([
        'naam' => 'Afhankelijke toestand',
        'code' => 'DST-'.Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $medewerkerId = DB::table('medewerkers')->insertGetId([
        'user_id' => $userId,
        'team_id' => $teamId,
        'medewerker_nummer' => 'DST-'.Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $functieSoortId = DB::table('functie_soorten')->insertGetId([
        'naam' => 'Volger toestand',
        'code' => 'DST-'.Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('functies')->insert([
        'medewerker_id' => $medewerkerId,
        'functie_soort_id' => $functieSoortId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('autorisatie_rollen')->insert([
        'functie_soort_id' => $functieSoortId,
        'rechtsgrond_id' => $rechtsgrondId,
        'naam' => 'Volgrol toestand',
        'code' => 'DST-'.Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
