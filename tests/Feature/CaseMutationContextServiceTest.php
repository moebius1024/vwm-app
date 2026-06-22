<?php

use App\Models\User;
use App\Services\CaseAccessService;
use App\Services\CaseMutationContextService;
use App\Services\CaseTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createCaseMutationContextCase(User $user, string $suffix): object
{
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => "CaseMutationContext Rechtsgrond {$suffix}",
        'code' => "CASEMUTCTX-RG-{$suffix}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => "CaseMutationContext CaseSoort {$suffix}",
        'code' => "CASEMUTCTX-CS-{$suffix}",
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

function createTransactionType(string $suffix): int
{
    return DB::table('transactie_soorten')->insertGetId([
        'naam' => "CaseMutationContext Transactie {$suffix}",
        'rdf_uri' => "http://example.test/transactie/{$suffix}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('it resolves store context with accessible case and primary dossier', function () {
    $user = User::factory()->create();
    $case = createCaseMutationContextCase($user, 'STORE');

    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.Str::uuid(),
        'case_id' => $case->case_id,
        'parent_id' => null,
        'naam' => 'Hoofddossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $context = app(CaseMutationContextService::class)->resolveStoreContext($case->case_id, $user->id);

    expect($context['reason'] ?? null)->toBeNull()
        ->and((int) $context['case']->id)->toBe($case->case_id)
        ->and((int) $context['dossier']->id)->toBe($dossierId);
});

test('it resolves follow context with dossier and transaction type', function () {
    $user = User::factory()->create();
    $case = createCaseMutationContextCase($user, 'FOLLOW');

    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.Str::uuid(),
        'case_id' => $case->case_id,
        'parent_id' => null,
        'naam' => 'Hoofddossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactieSoortId = createTransactionType('FOLLOW');

    DB::table('case_soort_transactie')->insert([
        'case_soort_id' => $case->case_soort_id,
        'transactie_soort_id' => $transactieSoortId,
        'volgorde' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $context = app(CaseMutationContextService::class)->resolveFollowContext($case->case_id, $user->id);

    expect($context['reason'] ?? null)->toBeNull()
        ->and((int) $context['case']->id)->toBe($case->case_id)
        ->and((int) $context['transactie_soort_id'])->toBe($transactieSoortId)
        ->and((int) $context['dossier']->id)->toBe($dossierId);
});

test('it reports dossier missing in follow context', function () {
    $user = User::factory()->create();
    $case = createCaseMutationContextCase($user, 'NODOSSIER');

    $context = app(CaseMutationContextService::class)->resolveFollowContext($case->case_id, $user->id);

    expect($context['reason'])->toBe('dossier_missing')
        ->and((int) $context['case']->id)->toBe($case->case_id);
});

test('it reports missing transaction type in unfollow context', function () {
    $user = User::factory()->create();
    $case = createCaseMutationContextCase($user, 'NOTRANS');

    $service = new CaseMutationContextService(
        new CaseAccessService,
        new class extends CaseTransactionService
        {
            public function resolveDefaultTransactionTypeId(int $caseSoortId): ?int
            {
                return null;
            }
        }
    );

    $context = $service->resolveUnfollowContext($case->case_id, $user->id);

    expect($context['reason'])->toBe('transactie_soort_missing')
        ->and((int) $context['case']->id)->toBe($case->case_id);
});
