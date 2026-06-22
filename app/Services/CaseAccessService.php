<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CaseAccessService
{
    /**
     * @param  array<int, string>  $columns
     */
    public function findUserCase(int $caseId, int $userId, array $columns = ['id']): ?object
    {
        return DB::table('cases')
            ->where('id', $caseId)
            ->where('user_id', $userId)
            ->first($columns);
    }

    /**
     * @param  array<int, string>  $columns
     */
    public function findPrimaryDossierForCase(int $caseId, array $columns = ['*']): ?object
    {
        return DB::table('dossiers')
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->first($columns);
    }
}
