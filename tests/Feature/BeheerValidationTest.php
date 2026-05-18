<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('validates required fields when storing a team', function () {
    $user = User::factory()->create();
    grantBeheerRoleToUser($user->id);

    $this->actingAs($user)
        ->from(route('beheer.index'))
        ->post(route('beheer.teams.store'), [
            'naam' => '',
            'code' => '',
        ])
        ->assertRedirect(route('beheer.index'))
        ->assertSessionHasErrors(['naam', 'code']);
});

it('stores team with valid payload', function () {
    $user = User::factory()->create();
    grantBeheerRoleToUser($user->id);

    $this->actingAs($user)
        ->post(route('beheer.teams.store'), [
            'naam' => 'Opsporing Noord',
            'code' => 'OPS-NOORD',
        ])
        ->assertRedirect();

    expect(DB::table('teams')->where('code', 'OPS-NOORD')->exists())->toBeTrue();
});

it('validates functie_soort_id when storing a medewerker', function () {
    $user = User::factory()->create();
    grantBeheerRoleToUser($user->id);

    $this->actingAs($user)
        ->from(route('beheer.index'))
        ->post(route('beheer.medewerkers.store'), [
            'medewerker_nummer' => 'M-100',
            'team_id' => null,
            'user_id' => null,
            'functie_soort_id' => null,
        ])
        ->assertRedirect(route('beheer.index'))
        ->assertSessionHasErrors(['functie_soort_id']);
});

function grantBeheerRoleToUser(int $userId): void
{
    $now = now();

    $functieSoortId = DB::table('functie_soorten')->insertGetId([
        'naam' => 'Beheer Functie',
        'code' => 'BEHEER',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $teamId = DB::table('teams')->insertGetId([
        'naam' => 'Beheer Team',
        'code' => 'BEHEER-TEAM-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $medewerkerId = DB::table('medewerkers')->insertGetId([
        'user_id' => $userId,
        'medewerker_nummer' => 'MB-'.uniqid(),
        'team_id' => $teamId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('functies')->insert([
        'medewerker_id' => $medewerkerId,
        'functie_soort_id' => $functieSoortId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

