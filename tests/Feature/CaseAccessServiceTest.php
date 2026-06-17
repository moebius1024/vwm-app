<?php

use App\Models\User;
use App\Services\CaseAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createCaseAccessCase(User $user, string $suffix): object
{
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => "CaseAccess Rechtsgrond {$suffix}",
        'code' => "CASEACCESS-RG-{$suffix}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => "CaseAccess CaseSoort {$suffix}",
        'code' => "CASEACCESS-CS-{$suffix}",
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

    return (object) [
        'rechtsgrond_id' => $rechtsgrondId,
        'case_soort_id' => $caseSoortId,
        'case_id' => $caseId,
    ];
}

test('it finds a case for the owning user', function () {
    $user = User::factory()->create();
    $case = createCaseAccessCase($user, 'OWNER');

    $foundCase = app(CaseAccessService::class)->findUserCase($case->case_id, $user->id, ['id', 'case_soort_id']);

    expect($foundCase)->not->toBeNull()
        ->and((int) $foundCase->id)->toBe($case->case_id)
        ->and((int) $foundCase->case_soort_id)->toBe($case->case_soort_id);
});

test('it does not find a case for another user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $case = createCaseAccessCase($owner, 'OTHER');

    expect(app(CaseAccessService::class)->findUserCase($case->case_id, $otherUser->id))->toBeNull();
});

test('it returns the primary dossier as the dossier with the lowest id', function () {
    $user = User::factory()->create();
    $case = createCaseAccessCase($user, 'DOSSIER');

    $primaryDossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.Str::uuid(),
        'case_id' => $case->case_id,
        'parent_id' => null,
        'naam' => 'Hoofddossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.Str::uuid(),
        'case_id' => $case->case_id,
        'parent_id' => $primaryDossierId,
        'naam' => 'Subdossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dossier = app(CaseAccessService::class)->findPrimaryDossierForCase($case->case_id, ['id', 'naam']);

    expect($dossier)->not->toBeNull()
        ->and((int) $dossier->id)->toBe($primaryDossierId)
        ->and($dossier->naam)->toBe('Hoofddossier');
});

test('it returns null when a case has no dossier', function () {
    $user = User::factory()->create();
    $case = createCaseAccessCase($user, 'NODOSSIER');

    expect(app(CaseAccessService::class)->findPrimaryDossierForCase($case->case_id))->toBeNull();
});
