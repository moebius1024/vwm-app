<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the dedicated case selection page when consulting without a case', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cases.consult'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('mode', 'consult')
            ->has('cases')
            ->has('teamNaam'),
        );
});

it('keeps the start page in start mode', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cases.start'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('mode', 'start')
            ->has('caseSoorten'),
        );
});

it('sorts the case selection by descending case number', function () {
    $user = User::factory()->create();
    $caseSoortIds = seedCaseSelectionAuthorization($user->id);

    $zebraCaseId = createCaseForSelection($user->id, $caseSoortIds['Zebra']);
    $firstAlfaCaseId = createCaseForSelection($user->id, $caseSoortIds['Alfa']);
    $secondAlfaCaseId = createCaseForSelection($user->id, $caseSoortIds['Alfa']);

    $this->actingAs($user)
        ->get(route('cases.start'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cases/Start')
            ->where('cases.0.id', $secondAlfaCaseId)
            ->where('cases.0.case_soort_naam', 'Alfa')
            ->where('cases.1.id', $firstAlfaCaseId)
            ->where('cases.1.case_soort_naam', 'Alfa')
            ->where('cases.2.id', $zebraCaseId)
            ->where('cases.2.case_soort_naam', 'Zebra')
            ->where('cases.0.case_soort_id', $caseSoortIds['Alfa']),
        );
});

/**
 * @return array<string, int>
 */
function seedCaseSelectionAuthorization(int $userId): array
{
    $now = now();
    $teamId = DB::table('teams')->insertGetId([
        'naam' => 'Selectieteam',
        'code' => 'SELECTIE-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $medewerkerId = DB::table('medewerkers')->insertGetId([
        'user_id' => $userId,
        'team_id' => $teamId,
        'medewerker_nummer' => 'SELECTIE-MW-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $functieSoortId = DB::table('functie_soorten')->insertGetId([
        'naam' => 'Selectiefunctie',
        'code' => 'SELECTIE-FUNC-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('functies')->insert([
        'medewerker_id' => $medewerkerId,
        'functie_soort_id' => $functieSoortId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $caseSoortIds = [];
    foreach (['Alfa', 'Zebra'] as $naam) {
        $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
            'naam' => "Rechtsgrond {$naam}",
            'code' => 'SELECTIE-RG-'.strtoupper($naam).'-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $caseSoortIds[$naam] = DB::table('case_soorten')->insertGetId([
            'naam' => $naam,
            'code' => 'SELECTIE-CS-'.strtoupper($naam).'-'.uniqid(),
            'rechtsgrond_id' => $rechtsgrondId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('autorisatie_rollen')->insert([
            'functie_soort_id' => $functieSoortId,
            'rechtsgrond_id' => $rechtsgrondId,
            'naam' => "Selectierol {$naam}",
            'code' => 'SELECTIE-AUT-'.strtoupper($naam).'-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $caseSoortIds;
}

function createCaseForSelection(int $userId, int $caseSoortId): int
{
    $now = now();
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Selectietransactie '.uniqid(),
        'rdf_uri' => 'http://vwm.voorbeeld.nl/transactie/'.Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $caseId,
        'transactie_soort_id' => $transactieSoortId,
        'user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('object_mutaties')->insert([
        'transactie_id' => $transactieId,
        'sjabloon_uri' => 'http://vwm.voorbeeld.nl/sjabloon/'.Str::uuid(),
        'data' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $caseId;
}
