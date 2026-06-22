<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('shares beheer navigation permission for users with beheer authorization', function () {
    $user = User::factory()->create();
    grantBeheerNavigationRoleToUser($user->id, 'HOOFDAGENT', 'AUTROL-BEHEER-HOOFDAGENT', 'BEHEER');

    $this->actingAs($user)
        ->get(route('cases.start'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('auth.can.beheer', true),
        );
});

it('does not share beheer navigation permission for users without beheer authorization', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cases.start'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('auth.can.beheer', false),
        );
});

function grantBeheerNavigationRoleToUser(int $userId, string $functieCode, string $autorisatieCode, string $autorisatieNaam): void
{
    $now = now();

    $functieSoortId = DB::table('functie_soorten')->insertGetId([
        'naam' => 'Test Functie',
        'code' => $functieCode,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test Rechtsgrond',
        'code' => 'TR-'.uniqid(),
        'omschrijving' => 'Test rechtsgrond voor beheer-navigatie.',
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

    DB::table('autorisatie_rollen')->insert([
        'functie_soort_id' => $functieSoortId,
        'rechtsgrond_id' => $rechtsgrondId,
        'naam' => $autorisatieNaam,
        'code' => $autorisatieCode,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
