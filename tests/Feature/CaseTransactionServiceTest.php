<?php

use App\Services\CaseTransactionService;
use Illuminate\Support\Facades\DB;

test('it resolves the first configured transaction type for a case soort', function () {
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'TEST-RG',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Test case soort',
        'code' => 'TEST-CS',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $firstTransactionTypeId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Tweede keuze',
        'rdf_uri' => 'http://example.test/transactie/tweede',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $preferredTransactionTypeId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Eerste keuze',
        'rdf_uri' => 'http://example.test/transactie/eerste',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('case_soort_transactie')->insert([
        [
            'case_soort_id' => $caseSoortId,
            'transactie_soort_id' => $firstTransactionTypeId,
            'volgorde' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'case_soort_id' => $caseSoortId,
            'transactie_soort_id' => $preferredTransactionTypeId,
            'volgorde' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(app(CaseTransactionService::class)->resolveDefaultTransactionTypeId($caseSoortId))
        ->toBe($preferredTransactionTypeId);
});

test('it falls back to the first transaction type when a case soort has no mapping', function () {
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Fallback rechtsgrond',
        'code' => 'FALLBACK-RG',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Fallback case soort',
        'code' => 'FALLBACK-CS',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Fallback transactie',
        'rdf_uri' => 'http://example.test/transactie/fallback',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $expectedFallbackTransactionTypeId = (int) DB::table('transactie_soorten')
        ->orderBy('id')
        ->value('id');

    expect(app(CaseTransactionService::class)->resolveDefaultTransactionTypeId($caseSoortId))
        ->toBe($expectedFallbackTransactionTypeId);
});
