<?php

use App\Models\User;
use App\Services\GraphService;
use App\Services\SjabloonMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('shows accessible people from other cases and marks an existing shared GO', function () {
    $user = User::factory()->create();
    $rechtsgrondId = personSearchAuthorization($user->id);
    $source = personSearchGoic($user->id, $rechtsgrondId, 'Bron');
    $candidate = personSearchGoic($user->id, $rechtsgrondId, 'Andere case');
    $sourceUri = $source['rdf_uri'];
    $candidateUri = $candidate['rdf_uri'];
    $candidateTbUri = 'http://vwm.voorbeeld.nl/data/tb/candidate';
    $metadata = Mockery::mock(SjabloonMetadataService::class);
    $metadata
        ->shouldReceive('fetchTbClassCapabilitiesByTbClasses')
        ->once()
        ->andReturn([
            'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving' => [
                'is_kern_tb' => true,
                'is_role_beschrijving' => false,
            ],
        ]);
    $this->instance(SjabloonMetadataService::class, $metadata);

    $graph = Mockery::mock(GraphService::class);
    $graph->shouldReceive('query')->andReturnUsing(function (string $query) use ($sourceUri, $candidateUri, $candidateTbUri): array {
        if (str_contains($query, 'searchProperty')) {
            return [[
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person',
                'searchProperty' => 'http://ontologie.politie.nl/def/dpm#lastName',
                'searchValue' => 'Glaap',
            ]];
        }
        if (str_contains($query, 'CONTAINS')) {
            return [['goic' => $candidateUri]];
        }
        if (str_contains($query, 'SELECT DISTINCT ?goic ?tb ?tbClass')) {
            return [['goic' => $candidateUri, 'tb' => $candidateTbUri, 'tbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving']];
        }
        if (str_contains($query, 'SELECT ?goic ?go')) {
            return [
                ['goic' => $sourceUri, 'go' => 'http://vwm.voorbeeld.nl/data/go/persoon-1'],
                ['goic' => $candidateUri, 'go' => 'http://vwm.voorbeeld.nl/data/go/persoon-1'],
            ];
        }
        if (str_contains($query, 'SELECT ?tb ?p ?o')) {
            return [
                ['tb' => $candidateTbUri, 'p' => 'http://ontologie.politie.nl/def/dpm#lastName', 'o' => 'Glaap'],
                ['tb' => $candidateTbUri, 'p' => 'http://ontologie.politie.nl/def/dpm#birthDate', 'o' => '2026-07-09'],
            ];
        }

        return [];
    });
    $this->instance(GraphService::class, $graph);

    $this->actingAs($user)
        ->get(route('cases.find-in-other-case', ['case' => $source['case_id'], 'goic' => $source['id'], 'case_soort' => 42]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/FindInOtherCase')
            ->where('caseSoortId', 42)
            ->where('searched', true)
            ->has('candidates', 1)
            ->where('candidates.0.id', $candidate['id'])
            ->where('candidates.0.case_id', $candidate['case_id'])
            ->where('candidates.0.same_go', true)
            ->where('candidates.0.toestanden.0.presentation_sort_rank', 0)
            ->where('candidates.0.toestanden.0.tb_data', fn ($data): bool => $data->get('http://ontologie.politie.nl/def/dpm#birthDate') === '2026-07-09'),
        );
});

it('shows the source state for an accessible dependent state in a search result', function () {
    $user = User::factory()->create();
    $rechtsgrondId = personSearchAuthorization($user->id);
    $source = personSearchGoic($user->id, $rechtsgrondId, 'Bron');
    $candidate = personSearchGoic($user->id, $rechtsgrondId, 'Andere case');
    $sourceUri = $source['rdf_uri'];
    $candidateUri = $candidate['rdf_uri'];
    $dependentTbUri = 'http://vwm.voorbeeld.nl/data/tb/dependent';
    $sourceTbUri = 'http://vwm.voorbeeld.nl/data/tb/contact';

    $graph = Mockery::mock(GraphService::class);
    $graph->shouldReceive('query')->andReturnUsing(function (string $query) use ($sourceUri, $candidateUri, $dependentTbUri, $sourceTbUri): array {
        if (str_contains($query, 'searchProperty')) {
            return [[
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person',
                'searchProperty' => 'http://ontologie.politie.nl/def/dpm#lastName',
                'searchValue' => 'Glaap',
            ]];
        }
        if (str_contains($query, 'CONTAINS')) {
            return [['goic' => $candidateUri]];
        }
        if (str_contains($query, 'SELECT DISTINCT ?goic ?tb ?tbClass')) {
            return [[
                'goic' => $candidateUri,
                'tb' => $dependentTbUri,
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#AfhankelijkeTB',
            ]];
        }
        if (str_contains($query, 'SELECT ?goic ?go')) {
            return [
                ['goic' => $sourceUri, 'go' => 'http://vwm.voorbeeld.nl/data/go/persoon-1'],
                ['goic' => $candidateUri, 'go' => 'http://vwm.voorbeeld.nl/data/go/persoon-2'],
            ];
        }
        if (str_contains($query, 'VALUES ?tb') && str_contains($query, 'SELECT ?tb ?p ?o')) {
            if (str_contains($query, "<{$dependentTbUri}>")) {
                return [[
                    'tb' => $dependentTbUri,
                    'p' => 'http://ontologie.politie.nl/def/vwm#verwijstNaar',
                    'o' => $sourceTbUri,
                ]];
            }

            return [[
                'tb' => $sourceTbUri,
                'p' => 'http://ontologie.politie.nl/def/dpm#emailAddress',
                'o' => 'glaap@example.test',
            ]];
        }
        if (str_contains($query, 'SELECT ?tb ?goic ?tbClass ?targetClass')) {
            return [[
                'tb' => $sourceTbUri,
                'goic' => $sourceUri,
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#ContactGegevens',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person',
            ]];
        }

        return [];
    });
    $this->instance(GraphService::class, $graph);

    $this->actingAs($user)
        ->get(route('cases.find-in-other-case', ['case' => $source['case_id'], 'goic' => $source['id']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates.0.toestanden.0.dependent_info.source_goic_id', $source['id'])
            ->where('candidates.0.toestanden.0.dependent_info.source_case_id', $source['case_id'])
            ->where('candidates.0.toestanden.0.dependent_info.source_target_class', 'http://ontologie.politie.nl/def/dpm#Person')
            ->where('candidates.0.toestanden.0.dependent_info.source_state.tb_data', fn ($data): bool => $data->get('http://ontologie.politie.nl/def/dpm#emailAddress') === 'glaap@example.test'),
        );
});

/** @return array{id:int,case_id:int,rdf_uri:string} */
function personSearchGoic(int $userId, int $rechtsgrondId, string $caseName): array
{
    $now = now();
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => $caseName,
        'code' => 'PS-'.Str::uuid(),
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

    return ['id' => $goicId, 'case_id' => $caseId, 'rdf_uri' => $rdfUri];
}

function personSearchAuthorization(int $userId): int
{
    $now = now();
    $teamId = DB::table('teams')->insertGetId(['naam' => 'Personenzoekteam', 'code' => 'PS-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    $medewerkerId = DB::table('medewerkers')->insertGetId(['user_id' => $userId, 'team_id' => $teamId, 'medewerker_nummer' => 'PS-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    $functieSoortId = DB::table('functie_soorten')->insertGetId(['naam' => 'Zoeker', 'code' => 'PS-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('functies')->insert(['medewerker_id' => $medewerkerId, 'functie_soort_id' => $functieSoortId, 'created_at' => $now, 'updated_at' => $now]);
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId(['naam' => 'Personenzoeken', 'code' => 'PS-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('autorisatie_rollen')->insert(['functie_soort_id' => $functieSoortId, 'rechtsgrond_id' => $rechtsgrondId, 'naam' => 'Zoekrol', 'code' => 'PS-'.Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);

    return $rechtsgrondId;
}
