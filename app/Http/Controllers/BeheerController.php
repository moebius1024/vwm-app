<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BeheerController extends Controller
{
    public function index(): Response
    {
        $teams = DB::table('teams')
            ->orderBy('naam')
            ->get(['id', 'naam', 'code']);

        $medewerkers = DB::table('medewerkers')
            ->join('teams', 'teams.id', '=', 'medewerkers.team_id')
            ->leftJoin('users', 'users.id', '=', 'medewerkers.user_id')
            ->orderBy('medewerkers.medewerker_nummer')
            ->get([
                'medewerkers.id',
                'medewerkers.medewerker_nummer',
                'medewerkers.user_id',
                'medewerkers.team_id',
                'teams.naam as team_naam',
                'users.email as user_email',
            ]);

        $personen = DB::table('personen')
            ->join('medewerkers', 'medewerkers.id', '=', 'personen.medewerker_id')
            ->orderBy('personen.naam')
            ->get([
                'personen.id',
                'personen.naam',
                'personen.identifier',
                'personen.medewerker_id',
                'medewerkers.medewerker_nummer',
            ]);

        $users = DB::table('users')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        return Inertia::render('beheer/Index', [
            'teams' => $teams,
            'medewerkers' => $medewerkers,
            'personen' => $personen,
            'users' => $users,
        ]);
    }

    public function storeTeam(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'naam' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:teams,code'],
        ]);

        DB::table('teams')->insert([
            'naam' => $validated['naam'],
            'code' => $validated['code'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back();
    }

    public function updateTeam(Request $request, int $teamId): RedirectResponse
    {
        $validated = $request->validate([
            'naam' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:teams,code,'.$teamId],
        ]);

        DB::table('teams')
            ->where('id', $teamId)
            ->update([
                'naam' => $validated['naam'],
                'code' => $validated['code'],
                'updated_at' => now(),
            ]);

        return back();
    }

    public function storeMedewerker(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'medewerker_nummer' => ['required', 'string', 'max:255', 'unique:medewerkers,medewerker_nummer'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        DB::table('medewerkers')->insert([
            'medewerker_nummer' => $validated['medewerker_nummer'],
            'team_id' => $validated['team_id'],
            'user_id' => $validated['user_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back();
    }

    public function updateMedewerker(Request $request, int $medewerkerId): RedirectResponse
    {
        $validated = $request->validate([
            'medewerker_nummer' => ['required', 'string', 'max:255', 'unique:medewerkers,medewerker_nummer,'.$medewerkerId],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        DB::table('medewerkers')
            ->where('id', $medewerkerId)
            ->update([
                'medewerker_nummer' => $validated['medewerker_nummer'],
                'team_id' => $validated['team_id'],
                'user_id' => $validated['user_id'] ?? null,
                'updated_at' => now(),
            ]);

        return back();
    }

    public function storePersoon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'naam' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:255', 'unique:personen,identifier'],
            'medewerker_id' => ['required', 'integer', 'exists:medewerkers,id', 'unique:personen,medewerker_id'],
        ]);

        DB::table('personen')->insert([
            'naam' => $validated['naam'],
            'identifier' => $validated['identifier'],
            'medewerker_id' => $validated['medewerker_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back();
    }

    public function updatePersoon(Request $request, int $persoonId): RedirectResponse
    {
        $validated = $request->validate([
            'naam' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:255', 'unique:personen,identifier,'.$persoonId],
            'medewerker_id' => ['required', 'integer', 'exists:medewerkers,id', 'unique:personen,medewerker_id,'.$persoonId],
        ]);

        DB::table('personen')
            ->where('id', $persoonId)
            ->update([
                'naam' => $validated['naam'],
                'identifier' => $validated['identifier'],
                'medewerker_id' => $validated['medewerker_id'],
                'updated_at' => now(),
            ]);

        return back();
    }
}

