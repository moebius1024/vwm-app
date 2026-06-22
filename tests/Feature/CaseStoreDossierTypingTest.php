<?php

use App\Models\User;
use App\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('copies incident requirement from case soort to initial dossier and RDF', function () {
    $user = User::factory()->create();
    grantCaseCreateRoleToUser($user->id);

    $capturedSparql = null;
    $mock = Mockery::mock(GraphService::class);
    $mock->shouldReceive('update')
        ->once()
        ->andReturnUsing(function (string $sparql) use (&$capturedSparql) {
            $capturedSparql = $sparql;

            return true;
        });
    app()->instance(GraphService::class, $mock);

    $caseSoortId = (int) DB::table('case_soorten')->value('id');

    $this->actingAs($user)
        ->post(route('cases.store'), ['case_soort_id' => $caseSoortId])
        ->assertRedirect();

    expect($capturedSparql)->not->toBeNull();
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#Dossier');
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#vereistIncidentInDossier');
    expect($capturedSparql)->not->toContain('http://ontologie.politie.nl/def/vwm#VerkeersincidentDossier');

    $dossier = DB::table('dossiers')->latest('id')->first(['vereist_incident_in_dossier']);
    expect((bool) $dossier->vereist_incident_in_dossier)->toBeTrue();
});

it('ignores configured dossier rdf types from sqlite mapping for new cases', function () {
    $user = User::factory()->create();
    grantCaseCreateRoleToUser($user->id);

    $capturedSparql = null;
    $mock = Mockery::mock(GraphService::class);
    $mock->shouldReceive('update')
        ->once()
        ->andReturnUsing(function (string $sparql) use (&$capturedSparql) {
            $capturedSparql = $sparql;

            return true;
        });
    app()->instance(GraphService::class, $mock);

    $caseSoortId = (int) DB::table('case_soorten')->value('id');
    DB::table('case_soort_dossier_types')->insert([
        'case_soort_id' => $caseSoortId,
        'rdf_type_uri' => 'http://ontologie.politie.nl/def/vwm#VerkeersincidentDossier',
        'volgorde' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('cases.store'), ['case_soort_id' => $caseSoortId])
        ->assertRedirect();

    expect($capturedSparql)->not->toBeNull();
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#Dossier');
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#vereistIncidentInDossier');
    expect($capturedSparql)->not->toContain('http://ontologie.politie.nl/def/vwm#VerkeersincidentDossier');
});

it('does not add incident requirement when case soort does not require it', function () {
    $user = User::factory()->create();
    grantCaseCreateRoleToUser($user->id);

    $caseSoortId = (int) DB::table('case_soorten')->value('id');
    DB::table('case_soorten')
        ->where('id', $caseSoortId)
        ->update(['vereist_incident_in_dossier' => false]);

    $capturedSparql = null;
    $mock = Mockery::mock(GraphService::class);
    $mock->shouldReceive('update')
        ->once()
        ->andReturnUsing(function (string $sparql) use (&$capturedSparql) {
            $capturedSparql = $sparql;

            return true;
        });
    app()->instance(GraphService::class, $mock);

    $this->actingAs($user)
        ->post(route('cases.store'), ['case_soort_id' => $caseSoortId])
        ->assertRedirect();

    expect($capturedSparql)->not->toBeNull();
    expect($capturedSparql)->toContain('http://ontologie.politie.nl/def/vwm#Dossier');
    expect($capturedSparql)->not->toContain('http://ontologie.politie.nl/def/vwm#vereistIncidentInDossier');

    $dossier = DB::table('dossiers')->latest('id')->first(['vereist_incident_in_dossier']);
    expect((bool) $dossier->vereist_incident_in_dossier)->toBeFalse();
});

function grantCaseCreateRoleToUser(int $userId): void
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

    $caseSoortId = 1001;
    $rechtsgrondId = 1001;
    DB::table('rechtsgronden')->insert([
        'id' => $rechtsgrondId,
        'naam' => 'Case Rechtsgrond',
        'code' => 'CASE-RG-'.uniqid(),
        'omschrijving' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('case_soorten')->insert([
        'id' => $caseSoortId,
        'naam' => 'Verkeersincident',
        'code' => 'VI-001',
        'rechtsgrond_id' => $rechtsgrondId,
        'vereist_incident_in_dossier' => true,
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
}
