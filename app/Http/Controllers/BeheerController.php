<?php

namespace App\Http\Controllers;

use App\Http\Requests\Beheer\StoreMedewerkerRequest;
use App\Http\Requests\Beheer\StorePersoonRequest;
use App\Http\Requests\Beheer\StoreTeamRequest;
use App\Http\Requests\Beheer\UpdateMedewerkerRequest;
use App\Http\Requests\Beheer\UpdatePersoonRequest;
use App\Http\Requests\Beheer\UpdateTeamRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BeheerController extends Controller
{
    public function index(): Response
    {
        $teams = DB::table('teams')->orderBy('naam')->get(['id', 'naam', 'code']);

        $functieSoorten = DB::table('functie_soorten')
            ->orderBy('naam')
            ->get(['id', 'naam', 'code']);

        $medewerkers = DB::table('medewerkers')
            ->leftJoin('teams', 'teams.id', '=', 'medewerkers.team_id')
            ->leftJoin('users', 'users.id', '=', 'medewerkers.user_id')
            ->leftJoin('functies', 'functies.medewerker_id', '=', 'medewerkers.id')
            ->leftJoin('functie_soorten', 'functie_soorten.id', '=', 'functies.functie_soort_id')
            ->leftJoin('personen', 'personen.medewerker_id', '=', 'medewerkers.id')
            ->orderBy('medewerkers.medewerker_nummer')
            ->get([
                'medewerkers.id',
                'medewerkers.medewerker_nummer',
                'medewerkers.user_id',
                'medewerkers.team_id',
                'teams.naam as team_naam',
                'users.email as user_email',
                'functie_soorten.id as functie_soort_id',
                'functie_soorten.naam as functie_soort_naam',
                'personen.naam as persoon_naam',
            ]);

        $personen = DB::table('personen')
            ->leftJoin('medewerkers', 'medewerkers.id', '=', 'personen.medewerker_id')
            ->leftJoin('teams', 'teams.id', '=', 'medewerkers.team_id')
            ->leftJoin('functies', 'functies.medewerker_id', '=', 'medewerkers.id')
            ->leftJoin('functie_soorten', 'functie_soorten.id', '=', 'functies.functie_soort_id')
            ->orderBy('personen.naam')
            ->get([
                'personen.id',
                'personen.naam',
                'personen.identifier',
                'personen.medewerker_id',
                'medewerkers.medewerker_nummer',
                'medewerkers.team_id',
                'teams.naam as team_naam',
                'functie_soorten.id as functie_soort_id',
                'functie_soorten.naam as functie_soort_naam',
            ]);

        $users = DB::table('users')->orderBy('id')->get(['id', 'name', 'email']);

        return Inertia::render('beheer/Index', [
            'teams' => $teams,
            'functieSoorten' => $functieSoorten,
            'medewerkers' => $medewerkers,
            'personen' => $personen,
            'users' => $users,
        ]);
    }

    public function storeTeam(StoreTeamRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::table('teams')->insert([
            'naam' => $validated['naam'],
            'code' => $validated['code'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back();
    }

    public function updateTeam(UpdateTeamRequest $request, int $team): RedirectResponse
    {
        $teamId = $team;
        $validated = $request->validated();

        DB::table('teams')->where('id', $teamId)->update([
            'naam' => $validated['naam'],
            'code' => $validated['code'],
            'updated_at' => now(),
        ]);

        return back();
    }

    public function storeMedewerker(StoreMedewerkerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated) {
            $medewerkerId = DB::table('medewerkers')->insertGetId([
                'medewerker_nummer' => $validated['medewerker_nummer'],
                'team_id' => $validated['team_id'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('functies')->insert([
                'medewerker_id' => $medewerkerId,
                'functie_soort_id' => $validated['functie_soort_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back();
    }

    public function updateMedewerker(UpdateMedewerkerRequest $request, int $medewerker): RedirectResponse
    {
        $medewerkerId = $medewerker;
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $medewerkerId) {
            DB::table('medewerkers')->where('id', $medewerkerId)->update([
                'medewerker_nummer' => $validated['medewerker_nummer'],
                'team_id' => $validated['team_id'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'updated_at' => now(),
            ]);

            $functie = DB::table('functies')->where('medewerker_id', $medewerkerId)->first(['id']);
            if ($functie) {
                DB::table('functies')->where('id', $functie->id)->update([
                    'functie_soort_id' => $validated['functie_soort_id'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('functies')->insert([
                    'medewerker_id' => $medewerkerId,
                    'functie_soort_id' => $validated['functie_soort_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return back();
    }

    public function storePersoon(StorePersoonRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::table('personen')->insert([
            'naam' => $validated['naam'],
            'identifier' => 'PERS-'.Str::upper((string) Str::uuid()),
            'medewerker_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back();
    }

    public function updatePersoon(UpdatePersoonRequest $request, int $persoon): RedirectResponse
    {
        $persoonId = $persoon;
        $persoon = DB::table('personen')->where('id', $persoonId)->first(['id', 'medewerker_id']);
        if (! $persoon) {
            return back()->withErrors(['persoon' => 'Persoon niet gevonden.']);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($persoon, $persoonId, $validated) {
            $medewerkerNummer = trim((string) ($validated['medewerker_nummer'] ?? ''));
            $medewerkerId = $persoon->medewerker_id ? (int) $persoon->medewerker_id : null;

            if ($medewerkerNummer !== '') {
                if ($medewerkerId) {
                    DB::table('medewerkers')->where('id', $medewerkerId)->update([
                        'medewerker_nummer' => $medewerkerNummer,
                        'team_id' => $validated['team_id'] ?? null,
                        'updated_at' => now(),
                    ]);
                } else {
                    $medewerkerId = DB::table('medewerkers')->insertGetId([
                        'medewerker_nummer' => $medewerkerNummer,
                        'team_id' => $validated['team_id'] ?? null,
                        'user_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $functie = DB::table('functies')->where('medewerker_id', $medewerkerId)->first(['id']);
                if ($functie) {
                    DB::table('functies')->where('id', $functie->id)->update([
                        'functie_soort_id' => $validated['functie_soort_id'],
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('functies')->insert([
                        'medewerker_id' => $medewerkerId,
                        'functie_soort_id' => $validated['functie_soort_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('personen')->where('id', $persoonId)->update([
                'naam' => $validated['naam'],
                'medewerker_id' => $medewerkerId,
                'updated_at' => now(),
            ]);
        });

        return back();
    }
}
