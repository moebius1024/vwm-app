<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureBeheerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            abort(403);
        }

        $hasBeheerRole = DB::table('medewerkers')
            ->join('functies', 'functies.medewerker_id', '=', 'medewerkers.id')
            ->join('functie_soorten', 'functie_soorten.id', '=', 'functies.functie_soort_id')
            ->leftJoin('autorisatie_rollen', 'autorisatie_rollen.functie_soort_id', '=', 'functies.functie_soort_id')
            ->where('medewerkers.user_id', $userId)
            ->where(function ($query) {
                $query->whereRaw('UPPER(autorisatie_rollen.code) = ?', ['BEHEER'])
                    ->orWhereRaw('UPPER(functie_soorten.code) = ?', ['BEHEER']);
            })
            ->exists();

        if (! $hasBeheerRole) {
            abort(403);
        }

        return $next($request);
    }
}
