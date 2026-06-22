<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CaseTransactionService
{
    public function resolveDefaultTransactionTypeId(int $caseSoortId): ?int
    {
        $transactionTypeId = DB::table('case_soort_transactie')
            ->where('case_soort_id', $caseSoortId)
            ->orderBy('volgorde')
            ->value('transactie_soort_id');

        if ($transactionTypeId) {
            return (int) $transactionTypeId;
        }

        $fallbackTransactionTypeId = DB::table('transactie_soorten')
            ->orderBy('id')
            ->value('id');

        return $fallbackTransactionTypeId ? (int) $fallbackTransactionTypeId : null;
    }
}
