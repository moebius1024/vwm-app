<?php

use App\Models\User;
use App\Services\GraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('moves every GOIC of the source identity to the selected existing identity', function () {
    $user = User::factory()->create();
    $rechtsgrondId = identityLinkAuthorization($user->id);
    $source = identityLinkGoic($user->id, $rechtsgrondId, 'Bron');
    $candidate = identityLinkGoic($user->id, $rechtsgrondId, 'Gevonden');
    $transactionTypeId = identityLinkDefaultTransactionType((int) $source['case_soort_id']);
    $otherUser = User::factory()->create();
    $relatedButInaccessible = identityLinkGoic($otherUser->id, $rechtsgrondId, 'Andere gebruiker');
    $sourceGo = 'http://vwm.voorbeeld.nl/data/go/bron';
    $candidateGo = 'http://vwm.voorbeeld.nl/data/go/behouden';

    $graph = Mockery::mock(GraphService::class);
    $graph->shouldReceive('query')->andReturnUsing(function (string $query) use ($source, $candidate, $relatedButInaccessible, $sourceGo, $candidateGo): array {
        if (str_contains($query, (string) $source['rdf_uri']) && str_contains($query, 'vwm:heeftDoelClass')) {
            return [['go' => $sourceGo, 'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person']];
        }
        if (str_contains($query, (string) $candidate['rdf_uri']) && str_contains($query, 'vwm:heeftDoelClass')) {
            return [['go' => $candidateGo, 'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person']];
        }
        if (str_contains($query, "<{$sourceGo}>") && str_contains($query, 'SELECT ?goic')) {
            return [
                ['goic' => $source['rdf_uri']],
                ['goic' => $relatedButInaccessible['rdf_uri']],
            ];
        }

        return [];
    });
    $graph->shouldReceive('update')->once()->with(Mockery::on(function (string $sparql) use ($source, $relatedButInaccessible, $sourceGo, $candidateGo): bool {
        return str_contains($sparql, (string) $source['rdf_uri'])
            && str_contains($sparql, (string) $relatedButInaccessible['rdf_uri'])
            && str_contains($sparql, "<{$sourceGo}>")
            && str_contains($sparql, "<{$candidateGo}>");
    }));
    $this->instance(GraphService::class, $graph);

    $this->actingAs($user)
        ->postJson(route('api.identity.link-existing.store'), [
            'source_case_id' => $source['case_id'],
            'source_goic_id' => $source['id'],
            'candidate_goic_id' => $candidate['id'],
            'confirmed' => true,
        ])
        ->assertSuccessful()
        ->assertJsonPath('moved_goic_count', 2);

    $mutation = DB::table('object_mutaties')->latest('id')->first(['data', 'gegevens_object_in_context_id', 'transactie_id']);
    expect((int) $mutation->gegevens_object_in_context_id)->toBe($source['id']);
    expect((int) DB::table('transacties')->where('id', $mutation->transactie_id)->value('transactie_soort_id'))
        ->toBe($transactionTypeId);
    expect(json_decode((string) $mutation->data, true))
        ->toMatchArray([
            'oudeGo' => $sourceGo,
            'behoudenGo' => $candidateGo,
            'verplaatsteGoics' => [$source['rdf_uri'], $relatedButInaccessible['rdf_uri']],
        ]);
});

/** @return array{id:int,case_id:int,case_soort_id:int,rdf_uri:string} */
function identityLinkGoic(int $userId, int $rechtsgrondId, string $caseName): array
{
    $now = now();
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => $caseName,
        'code' => 'IL-'.Str::uuid(),
        'rechtsgrond_id' => $rechtsgrondId,
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
        'naam' => "Dossier {$caseName}",
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $rdfUri = 'http://vwm.voorbeeld.nl/data/goic/'.Str::uuid();
    $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => $rdfUri,
        'dossier_id' => $dossierId,
        'context_data' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $goicId, 'case_id' => $caseId, 'case_soort_id' => $caseSoortId, 'rdf_uri' => $rdfUri];
}

function identityLinkDefaultTransactionType(int $caseSoortId): int
{
    $now = now();
    $transactionTypeId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Identiteitskoppeling broncase',
        'rdf_uri' => 'http://vwm.voorbeeld.nl/data/transactie-soort/'.Str::uuid(),
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

    return $transactionTypeId;
}

function identityLinkAuthorization(int $userId): int
{
    $now = now();
    $teamId = DB::table('teams')->insertGetId(['naam' => 'Identiteitskoppeling', 'code' => 'IL-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    $medewerkerId = DB::table('medewerkers')->insertGetId(['user_id' => $userId, 'team_id' => $teamId, 'medewerker_nummer' => 'IL-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    $functieSoortId = DB::table('functie_soorten')->insertGetId(['naam' => 'Koppelaar', 'code' => 'IL-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('functies')->insert(['medewerker_id' => $medewerkerId, 'functie_soort_id' => $functieSoortId, 'created_at' => $now, 'updated_at' => $now]);
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId(['naam' => 'Identiteitskoppeling', 'code' => 'IL-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('autorisatie_rollen')->insert(['functie_soort_id' => $functieSoortId, 'rechtsgrond_id' => $rechtsgrondId, 'naam' => 'Koppelrol', 'code' => 'IL-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);

    return $rechtsgrondId;
}
