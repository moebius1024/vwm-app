<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BeheerAccessService
{
    public function userHasBeheerAccess(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return DB::table('medewerkers')
            ->join('functies', 'functies.medewerker_id', '=', 'medewerkers.id')
            ->join('functie_soorten', 'functie_soorten.id', '=', 'functies.functie_soort_id')
            ->leftJoin('autorisatie_rollen', 'autorisatie_rollen.functie_soort_id', '=', 'functies.functie_soort_id')
            ->where('medewerkers.user_id', $userId)
            ->where(function ($query): void {
                $query->whereRaw('UPPER(autorisatie_rollen.code) = ?', ['BEHEER'])
                    ->orWhereRaw('UPPER(autorisatie_rollen.naam) = ?', ['BEHEER'])
                    ->orWhereRaw('UPPER(functie_soorten.code) = ?', ['BEHEER']);
            })
            ->exists();
    }
}
