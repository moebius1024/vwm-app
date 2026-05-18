<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('forbids beheer page for authenticated users without beheer role', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('beheer.index'))
        ->assertForbidden();
});

it('allows beheer page for authenticated users with beheer role', function () {
    $user = User::factory()->create();
    grantRoleToUser($user->id, 'BEHEER');

    $this->actingAs($user)
        ->get(route('beheer.index'))
        ->assertOk();
});

it('forbids beheer write routes without beheer role', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('beheer.teams.store'), [
            'naam' => 'Nieuw Team',
            'code' => 'NT-001',
        ])
        ->assertForbidden();
});

function grantRoleToUser(int $userId, string $rolCode): void
{
    $now = now();

    $functieSoortId = DB::table('functie_soorten')->insertGetId([
        'naam' => 'Test Functie',
        'code' => $rolCode,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $teamId = DB::table('teams')->insertGetId([
        'naam' => 'Test Team',
        'code' => 'TT-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $medewerkerId = DB::table('medewerkers')->insertGetId([
        'user_id' => $userId,
        'medewerker_nummer' => 'MN-'.uniqid(),
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
