<?php

use App\Models\User;
use App\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates an additional dossier within an authorized case', function () {
    $user = User::factory()->create();
    [$caseId, $caseSoortId] = seedAuthorizedCaseForUser($user->id);

    DB::table('case_soort_dossier_types')->insert([
        'case_soort_id' => $caseSoortId,
        'rdf_type_uri' => 'http://ontologie.politie.nl/def/vwm#VerkeersincidentDossier',
        'volgorde' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $capturedSparql = null;
    $mock = \Mockery::mock(GraphService::class);
    $mock->shouldReceive('update')
        ->once()
        ->andReturnUsing(function (string $sparql) use (&$capturedSparql) {
            $capturedSparql = $sparql;

            return true;
        });
    app()->instance(GraphService::class, $mock);

    $beforeCount = DB::table('dossiers')->where('case_id', $caseId)->count();

    $this->actingAs($user)
        ->post(route('cases.dossiers.store', ['case' => $caseId]), [
            'naam' => 'Subdossier Getuigen',
        ])
        ->assertRedirect(route('cases.edit', ['case' => $caseId], false));

    $afterCount = DB::table('dossiers')->where('case_id', $caseId)->count();
    expect($afterCount)->toBe($beforeCount + 1);
    expect(DB::table('dossiers')->where('case_id', $caseId)->where('naam', 'Subdossier Getuigen')->exists())->toBeTrue();
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#Dossier');
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#VerkeersincidentDossier');
});

it('forbids creating dossier in a case that user does not own', function () {
    $owner = User::factory()->create();
    [$caseId] = seedAuthorizedCaseForUser($owner->id);
    $otherUser = User::factory()->create();

    $mock = \Mockery::mock(GraphService::class);
    $mock->shouldReceive('update')->never();
    app()->instance(GraphService::class, $mock);

    $this->actingAs($otherUser)
        ->post(route('cases.dossiers.store', ['case' => $caseId]), [
            'naam' => 'Onbevoegd dossier',
        ])
        ->assertRedirect(route('cases.start', absolute: false));
});

it('creates an additional dossier with a parent dossier when parent_id is provided', function () {
    $user = User::factory()->create();
    [$caseId] = seedAuthorizedCaseForUser($user->id);
    $parentId = (int) DB::table('dossiers')->where('case_id', $caseId)->value('id');

    $mock = \Mockery::mock(GraphService::class);
    $mock->shouldReceive('update')->once()->andReturn(true);
    app()->instance(GraphService::class, $mock);

    $this->actingAs($user)
        ->post(route('cases.dossiers.store', ['case' => $caseId]), [
            'naam' => 'Child dossier',
            'parent_id' => $parentId,
        ])
        ->assertRedirect(route('cases.edit', ['case' => $caseId], false));

    $child = DB::table('dossiers')
        ->where('case_id', $caseId)
        ->where('naam', 'Child dossier')
        ->first(['id', 'parent_id']);

    expect($child)->not->toBeNull();
    expect((int) ($child->parent_id ?? 0))->toBe($parentId);
});

/**
 * @return array{int,int}
 */
function seedAuthorizedCaseForUser(int $userId): array
{
    $now = now();

    $teamId = DB::table('teams')->insertGetId([
        'naam' => 'Case Team',
        'code' => 'CASE-TEAM-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $medewerkerId = DB::table('medewerkers')->insertGetId([
        'user_id' => $userId,
        'medewerker_nummer' => 'CASE-MW-'.uniqid(),
        'team_id' => $teamId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $functieSoortId = DB::table('functie_soorten')->insertGetId([
        'naam' => 'Case Functie',
        'code' => 'CASE-FUNC-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('functies')->insert([
        'medewerker_id' => $medewerkerId,
        'functie_soort_id' => $functieSoortId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Case Rechtsgrond',
        'code' => 'CASE-RG-'.uniqid(),
        'omschrijving' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-001',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('autorisatie_rollen')->insert([
        'functie_soort_id' => $functieSoortId,
        'rechtsgrond_id' => $rechtsgrondId,
        'naam' => 'Case Autorisatie',
        'code' => 'CASE-AUT-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('dossiers')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'rdf_uri' => 'http://vwm.voorbeeld.nl/data/dossier/'.\Illuminate\Support\Str::uuid(),
        'case_id' => $caseId,
        'parent_id' => null,
        'naam' => 'Bestaand dossier',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$caseId, $caseSoortId];
}
