<?php

namespace App\Http\Controllers;

use App\Http\Requests\FindInOtherCaseRequest;
use App\Services\FindInOtherCaseService;
use App\Services\GraphService;
use App\Services\SjabloonMetadataService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    /**
     * Startpagina: kies bestaande case of maak een nieuwe.
     */
    public function index(Request $request): Response
    {
        return $this->caseSelection($request, 'start');
    }

    private function caseSelection(Request $request, string $mode): Response
    {
        $userId = $request->user()->id;
        $this->purgeEmptyCasesForUser($userId);
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($userId);
        $team = DB::table('medewerkers')
            ->leftJoin('teams', 'teams.id', '=', 'medewerkers.team_id')
            ->where('medewerkers.user_id', $userId)
            ->first([
                'medewerkers.team_id',
                'teams.naam as team_naam',
            ]);
        $teamId = $team?->team_id ? (int) $team->team_id : null;
        $teamNaam = is_string($team?->team_naam) ? $team->team_naam : null;

        $caseSoorten = DB::table('case_soorten')
            ->select('id', 'naam', 'code')
            ->whereIn('rechtsgrond_id', $allowedRechtsgrondIds)
            ->orderBy('naam')
            ->get();
        $selectedCaseSoortId = $request->integer('case_soort');

        if (! $caseSoorten->pluck('id')->contains($selectedCaseSoortId)) {
            $selectedCaseSoortId = null;
        }

        $visibleCases = $this->consultableCasesQuery($allowedRechtsgrondIds);
        $cases = $teamId === null
            ? collect()
            : (clone $visibleCases)
                ->when($mode === 'start', fn (Builder $query): Builder => $query->where('cases.user_id', $userId))
                ->where('latest_medewerker.team_id', $teamId)
                ->orderByDesc('cases.id')
                ->get();

        $otherTeamCases = collect();
        if ($mode === 'consult' && $teamId !== null) {
            $otherTeamCases = (clone $visibleCases)
                ->where('latest_medewerker.team_id', '!=', $teamId)
                ->orderBy('teams.naam')
                ->orderByDesc('cases.id')
                ->get()
                ->groupBy('team_id')
                ->map(function ($teamCases): array {
                    $team = $teamCases->first();

                    return [
                        'id' => (int) $team->team_id,
                        'naam' => (string) $team->team_naam,
                        'cases' => $teamCases->values()->all(),
                    ];
                })
                ->values();
        }

        return Inertia::render('cases/Start', [
            'caseSoorten' => $caseSoorten,
            'cases' => $cases,
            'teamNaam' => $teamNaam,
            'mode' => $mode,
            'selectedCaseSoortId' => $selectedCaseSoortId,
            'otherTeamCases' => $otherTeamCases,
        ]);
    }

    /**
     * Maak een nieuwe case aan voor de ingelogde gebruiker.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'case_soort_id' => ['required', 'integer', 'exists:case_soorten,id'],
        ]);
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($request->user()->id);

        $caseSoortAllowed = DB::table('case_soorten')
            ->where('id', $validated['case_soort_id'])
            ->whereIn('rechtsgrond_id', $allowedRechtsgrondIds)
            ->exists();

        if (! $caseSoortAllowed) {
            return redirect()
                ->route('cases.start')
                ->with('error', 'Je autorisatierol staat dit case-type niet toe.');
        }

        $caseId = DB::transaction(function () use ($validated, $request) {
            $caseSoort = DB::table('case_soorten')
                ->select('naam', 'vereist_incident_in_dossier')
                ->where('id', $validated['case_soort_id'])
                ->first();

            $caseUuid = (string) Str::uuid();
            $requiresIncidentInDossier = (bool) ($caseSoort?->vereist_incident_in_dossier ?? false);

            $caseId = DB::table('cases')->insertGetId([
                'uuid' => $caseUuid,
                'case_soort_id' => $validated['case_soort_id'],
                'user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dossierUuid = (string) Str::uuid();
            $dossierUri = "http://vwm.voorbeeld.nl/data/dossier/{$dossierUuid}";

            DB::table('dossiers')->insert([
                'uuid' => $dossierUuid,
                'rdf_uri' => $dossierUri,
                'case_id' => $caseId,
                'parent_id' => null,
                'naam' => $caseSoort?->naam ? "Dossier {$caseSoort->naam}" : 'Dossier',
                'vereist_incident_in_dossier' => $requiresIncidentInDossier,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dossierTriples = "
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        <{$dossierUri}> a <http://ontologie.politie.nl/def/vwm#Dossier> .
                        {$this->buildIncidentRequirementTriple($dossierUri, $requiresIncidentInDossier)}
                    }
                }
            ";

            app(GraphService::class)->update($dossierTriples);

            return $caseId;
        });

        return redirect()->route('cases.edit', ['case' => $caseId]);
    }

    /**
     * Maak een extra dossier aan binnen een bestaande case.
     */
    public function storeDossier(Request $request, int $case): RedirectResponse
    {
        $validated = $request->validate([
            'naam' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
        ]);
        $userId = (int) $request->user()->id;
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($userId);

        $caseRow = DB::table('cases')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('cases.id', $case)
            ->where('cases.user_id', $userId)
            ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
            ->first([
                'cases.id',
                'cases.case_soort_id',
                'case_soorten.naam as case_soort_naam',
            ]);

        if (! $caseRow) {
            return redirect()->route('cases.start')->with('error', 'Deze case is niet beschikbaar binnen jouw autorisatie.');
        }

        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        if ($parentId !== null && $parentId > 0) {
            $parentExists = DB::table('dossiers')
                ->where('id', $parentId)
                ->where('case_id', (int) $caseRow->id)
                ->exists();
            if (! $parentExists) {
                return redirect()->route('cases.edit', ['case' => (int) $caseRow->id])
                    ->with('error', 'Geselecteerd parent-dossier hoort niet bij deze case.');
            }
        } else {
            $parentId = null;
        }

        DB::transaction(function () use ($validated, $caseRow, $parentId) {
            $dossierUuid = (string) Str::uuid();
            $dossierUri = "http://vwm.voorbeeld.nl/data/dossier/{$dossierUuid}";
            $naam = trim((string) ($validated['naam'] ?? ''));
            if ($naam === '') {
                $naam = is_string($caseRow->case_soort_naam ?? null) && $caseRow->case_soort_naam !== ''
                    ? "Dossier {$caseRow->case_soort_naam}"
                    : 'Dossier';
            }

            DB::table('dossiers')->insert([
                'uuid' => $dossierUuid,
                'rdf_uri' => $dossierUri,
                'case_id' => (int) $caseRow->id,
                'parent_id' => $parentId,
                'naam' => $naam,
                'vereist_incident_in_dossier' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dossierTriples = "
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        <{$dossierUri}> a <http://ontologie.politie.nl/def/vwm#Dossier> .
                    }
                }
            ";

            app(GraphService::class)->update($dossierTriples);
        });

        return redirect()->route('cases.edit', ['case' => (int) $caseRow->id]);
    }

    /**
     * Bewerken: registreer + raadpleeg binnen dezelfde case.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $caseId = $request->integer('case');
        $case = null;
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($request->user()->id);

        if ($caseId) {
            $case = DB::table('cases')
                ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
                ->where('cases.id', $caseId)
                ->where('cases.user_id', $request->user()->id)
                ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
                ->first([
                    'cases.id',
                    'cases.uuid',
                    'cases.case_soort_id',
                    'case_soorten.naam as case_soort_naam',
                    'case_soorten.code as case_soort_code',
                ]);
        }

        if (! $case && $caseId) {
            return redirect('/start')->with('error', 'Deze case is niet beschikbaar binnen jouw autorisatie.');
        }

        $transactieSoorten = [];
        if ($case && $case->case_soort_id) {
            $transactieSoorten = DB::table('case_soort_transactie')
                ->join('transactie_soorten', 'transactie_soorten.id', '=', 'case_soort_transactie.transactie_soort_id')
                ->where('case_soort_transactie.case_soort_id', $case->case_soort_id)
                ->orderBy('case_soort_transactie.volgorde')
                ->get([
                    'transactie_soorten.id',
                    'transactie_soorten.naam',
                ]);
        }

        $dossiersOut = [];
        if ($case) {
            $dossiersOut = $this->buildDossiersOut($case->id, $request->user()->id, $allowedRechtsgrondIds);
        }

        return Inertia::render('cases/Edit', [
            'activeCase' => $case,
            'caseId' => $case?->id,
            'transactieSoorten' => $transactieSoorten,
            'dossiers' => $dossiersOut,
        ]);
    }

    /**
     * Raadplegen: kies bestaande case en bekijk dossier(s) + inhoud.
     */
    public function consult(Request $request): Response|RedirectResponse
    {
        $userId = $request->user()->id;
        $this->purgeEmptyCasesForUser($userId);
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($userId);
        $caseId = $request->integer('case');
        $followTargetCaseId = $request->query('follow_mode') === 'edit'
            ? $request->integer('follow_target_case')
            : 0;
        $goUri = trim((string) $request->query('go', ''));

        if (! $caseId && ! $followTargetCaseId && $goUri === '') {
            return $this->caseSelection($request, 'consult');
        }

        $cases = $this->consultableCasesQuery($allowedRechtsgrondIds)
            ->orderByDesc('cases.created_at')
            ->get();

        $activeCase = null;
        $dossiersOut = [];

        if ($caseId) {
            $activeCase = $cases->firstWhere('id', $caseId);
        }

        if ($caseId && ! $activeCase) {
            return redirect('/raadplegen')->with('error', 'Deze case is niet beschikbaar binnen jouw autorisatie.');
        }

        if ($activeCase) {
            $dossiersOut = $this->buildDossiersOut((int) $activeCase->id, $userId, $allowedRechtsgrondIds);
        }

        return Inertia::render('cases/Consult', [
            'activeCase' => $activeCase,
            'dossiers' => $dossiersOut,
            'followTargetCaseId' => $followTargetCaseId ?: null,
            'goUri' => $this->isAllowedHttpUri($goUri) ? $goUri : null,
        ]);
    }

    public function consultGo(Request $request, SjabloonMetadataService $sjabloonMetadataService): Response
    {
        $validated = $request->validate([
            'go' => ['required', 'string'],
            'case' => ['nullable', 'integer'],
            'origin_goic' => ['nullable', 'integer'],
            'mode' => ['nullable', 'in:consult,edit'],
        ]);

        $goUri = trim((string) ($validated['go'] ?? ''));
        $selectedCaseId = isset($validated['case']) ? (int) $validated['case'] : null;
        $originGoicId = isset($validated['origin_goic']) ? (int) $validated['origin_goic'] : null;
        $originMode = ($validated['mode'] ?? null) === 'edit' ? 'edit' : 'consult';

        if (! $this->isAllowedHttpUri($goUri)) {
            abort(404);
        }

        $userId = $request->user()->id;
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($userId);
        $goicUris = $this->fetchGoicUrisByGoUri($goUri);
        $goicUris = $this->filterActiveGoicUris($goicUris);
        $goics = collect();

        if (! empty($goicUris)) {
            $goics = DB::table('gegevens_objecten_in_context')
                ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
                ->join('cases', 'cases.id', '=', 'dossiers.case_id')
                ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
                ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
                ->whereIn('gegevens_objecten_in_context.rdf_uri', $goicUris)
                ->orderBy('cases.id')
                ->orderBy('dossiers.id')
                ->orderBy('gegevens_objecten_in_context.id')
                ->get([
                    'gegevens_objecten_in_context.id as goic_id',
                    'gegevens_objecten_in_context.rdf_uri as goic_rdf_uri',
                    'gegevens_objecten_in_context.dossier_id',
                    'gegevens_objecten_in_context.created_at as goic_created_at',
                    'dossiers.naam as dossier_naam',
                    'cases.id as case_id',
                    'case_soorten.naam as case_soort_naam',
                    'case_soorten.code as case_soort_code',
                ]);
        }

        if ($originGoicId !== null && ($selectedCaseId === null || ! $goics->contains(fn (object $goic): bool => (int) $goic->goic_id === $originGoicId && (int) $goic->case_id === $selectedCaseId))) {
            abort(404);
        }

        $goicMap = [];
        foreach ($goics as $goic) {
            $goicId = (int) $goic->goic_id;
            $goicMap[$goicId] = [
                'id' => $goicId,
                'rdf_uri' => $goic->goic_rdf_uri,
                'dossier_id' => (int) $goic->dossier_id,
                'dossier_naam' => $goic->dossier_naam,
                'case_id' => (int) $goic->case_id,
                'case_soort_naam' => $goic->case_soort_naam,
                'case_soort_code' => $goic->case_soort_code,
                'created_at' => $goic->goic_created_at,
                'toestanden' => [],
            ];
        }
        $goicUriById = [];
        foreach ($goics as $goic) {
            $goicUriById[(int) $goic->goic_id] = (string) $goic->goic_rdf_uri;
        }
        $toestandenByGoicUri = $this->fetchActiveToestandenByGoicUris(array_values($goicUriById));
        $tbUris = [];
        foreach ($toestandenByGoicUri as $rows) {
            foreach ($rows as $row) {
                if (is_string($row['tb_rdf_uri'] ?? null) && ($row['tb_rdf_uri'] ?? '') !== '') {
                    $tbUris[] = $row['tb_rdf_uri'];
                }
            }
        }
        $tbDataByUri = $this->fetchTbDataByUris($tbUris);
        $tbAuditByUri = $this->fetchTbAuditMetaByUris($tbUris);
        $alreadyFollowedSourceTbUris = $originGoicId !== null
            ? $this->fetchFollowedSourceTbUris($goicUriById[$originGoicId] ?? null)
            : [];
        $tbClasses = collect($toestandenByGoicUri)
            ->flatten(1)
            ->pluck('tb_class')
            ->filter(fn (mixed $tbClass): bool => is_string($tbClass) && $tbClass !== '')
            ->unique()
            ->values()
            ->all();
        $tbCapabilities = $sjabloonMetadataService->fetchTbClassCapabilitiesByTbClasses($tbClasses);

        foreach ($goicMap as $goicId => &$entry) {
            $goicUri = $goicUriById[(int) $goicId] ?? null;
            if (! is_string($goicUri) || $goicUri === '') {
                continue;
            }
            $rows = $toestandenByGoicUri[$goicUri] ?? [];
            foreach ($rows as $row) {
                $tbUri = $row['tb_rdf_uri'] ?? null;
                if (! is_string($tbUri) || $tbUri === '') {
                    continue;
                }
                $audit = $tbAuditByUri[$tbUri] ?? null;
                $entry['toestanden'][] = [
                    'mutatie_id' => is_array($audit) ? ($audit['mutatie_id'] ?? null) : null,
                    'sjabloon_uri' => is_array($audit) ? ($audit['sjabloon_uri'] ?? null) : ($row['tb_class'] ?? null),
                    'tb_id' => is_array($audit) ? ($audit['tb_id'] ?? null) : null,
                    'tb_rdf_uri' => $tbUri,
                    'tb_class' => $row['tb_class'] ?? null,
                    'tb_data' => $tbDataByUri[$tbUri] ?? null,
                    'created_at' => is_array($audit) ? ($audit['created_at'] ?? null) : null,
                    'can_follow_as_dependent' => $this->canFollowAsDependent($row['tb_class'] ?? null, $tbCapabilities),
                    'already_followed_by_origin' => isset($alreadyFollowedSourceTbUris[$tbUri]),
                ];
            }
        }
        unset($entry);

        $this->attachDependentStateInfo($goicMap, $allowedRechtsgrondIds);
        $this->attachFollowInfoToGoics($goicMap);

        $goicsOut = array_values($goicMap);
        usort($goicsOut, function (array $a, array $b) {
            $caseCompare = ($a['case_id'] ?? 0) <=> ($b['case_id'] ?? 0);
            if ($caseCompare !== 0) {
                return $caseCompare;
            }

            $dossierCompare = ($a['dossier_id'] ?? 0) <=> ($b['dossier_id'] ?? 0);
            if ($dossierCompare !== 0) {
                return $dossierCompare;
            }

            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        return Inertia::render('cases/GoLinks', [
            'goUri' => $goUri,
            'selectedCaseId' => $selectedCaseId,
            'originGoicId' => $originGoicId,
            'originMode' => $originMode,
            'goics' => $goicsOut,
        ]);
    }

    /**
     * Vind registraties in andere cases op basis van ontologie-metadata.
     */
    public function findInOtherCase(
        FindInOtherCaseRequest $request,
        FindInOtherCaseService $findInOtherCaseService,
        SjabloonMetadataService $sjabloonMetadataService,
    ): Response {
        $validated = $request->validated();
        $userId = (int) $request->user()->id;
        $allowedRechtsgrondIds = $this->allowedRechtsgrondIdsForUser($userId);
        $source = DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('gegevens_objecten_in_context.id', $validated['goic'])
            ->where('cases.id', $validated['case'])
            ->where('cases.user_id', $userId)
            ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
            ->first([
                'gegevens_objecten_in_context.id',
                'gegevens_objecten_in_context.rdf_uri',
                'cases.id as case_id',
            ]);

        if (! $source || ! $this->isAllowedHttpUri($source->rdf_uri)) {
            abort(404);
        }

        $searchMetadata = $findInOtherCaseService->searchMetadataForGoic($source->rdf_uri);
        if ($searchMetadata === null) {
            abort(404);
        }

        $query = trim($searchMetadata['search_value']);
        $candidateUris = mb_strlen($query) >= 2
            ? $findInOtherCaseService->findGoicsByValue($searchMetadata['target_class'], $searchMetadata['search_property'], $query)
            : [];
        $candidates = collect();

        if ($candidateUris !== []) {
            $candidates = DB::table('gegevens_objecten_in_context')
                ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
                ->join('cases', 'cases.id', '=', 'dossiers.case_id')
                ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
                ->where('cases.user_id', $userId)
                ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
                ->where('cases.id', '!=', $source->case_id)
                ->whereIn('gegevens_objecten_in_context.rdf_uri', $candidateUris)
                ->orderByDesc('cases.id')
                ->limit(50)
                ->get([
                    'gegevens_objecten_in_context.id',
                    'gegevens_objecten_in_context.rdf_uri',
                    'dossiers.id as dossier_id',
                    'dossiers.naam as dossier_naam',
                    'cases.id as case_id',
                    'case_soorten.naam as case_soort_naam',
                ]);
        }

        $candidateUris = $this->filterActiveGoicUris($candidates->pluck('rdf_uri')->all());
        $candidates = $candidates->filter(fn (object $candidate): bool => in_array($candidate->rdf_uri, $candidateUris, true));
        $goUris = $findInOtherCaseService->goUrisByGoicUris([
            $source->rdf_uri,
            ...$candidates->pluck('rdf_uri')->all(),
        ]);

        return Inertia::render('cases/FindInOtherCase', [
            'caseSoortId' => isset($validated['case_soort']) ? (int) $validated['case_soort'] : null,
            'source' => [
                'caseId' => (int) $source->case_id,
                'goicId' => (int) $source->id,
                'goUri' => $goUris[$source->rdf_uri] ?? null,
                'resultLabel' => $searchMetadata['result_label'] ?? 'Object',
                'sameIdentityActionLabel' => $searchMetadata['same_identity_action_label'] ?? 'Zelfde registratie',
                'alreadyLinkedLabel' => $searchMetadata['already_linked_label'] ?? 'Al gekoppeld via Verwijzing',
            ],
            'query' => $query,
            'searched' => mb_strlen($query) >= 2,
            'candidates' => $this->attachToestandSortRanksToFindCandidates(
                $this->attachDependentStateInfoToFindCandidates(
                    $this->buildFindInOtherCaseCandidates($candidates->all(), $goUris[$source->rdf_uri] ?? null, $goUris),
                    $userId,
                    $allowedRechtsgrondIds,
                ),
                $sjabloonMetadataService,
            ),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function attachToestandSortRanksToFindCandidates(
        array $candidates,
        SjabloonMetadataService $sjabloonMetadataService,
    ): array {
        $tbClasses = collect($candidates)
            ->pluck('toestanden')
            ->flatten(1)
            ->pluck('tb_class')
            ->filter(fn (mixed $tbClass): bool => is_string($tbClass) && $tbClass !== '')
            ->unique()
            ->values()
            ->all();
        $capabilitiesByTbClass = $sjabloonMetadataService->fetchTbClassCapabilitiesByTbClasses($tbClasses);

        foreach ($candidates as &$candidate) {
            foreach ($candidate['toestanden'] as &$toestand) {
                $capabilities = $capabilitiesByTbClass[$toestand['tb_class'] ?? ''] ?? [];
                $toestand['presentation_sort_rank'] = ($capabilities['is_kern_tb'] ?? false)
                    ? 0
                    : (($capabilities['is_role_beschrijving'] ?? false) ? 1 : 2);
            }
            unset($toestand);
        }
        unset($candidate);

        return $candidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, int>  $allowedRechtsgrondIds
     * @return array<int, array<string, mixed>>
     */
    private function attachDependentStateInfoToFindCandidates(array $candidates, int $userId, array $allowedRechtsgrondIds): array
    {
        $goicMap = [];
        foreach ($candidates as $index => $candidate) {
            $goicMap[$index] = [
                'toestanden' => $candidate['toestanden'] ?? [],
            ];
        }

        $this->attachDependentStateInfo($goicMap, $allowedRechtsgrondIds);

        foreach ($candidates as $index => &$candidate) {
            $candidate['toestanden'] = $goicMap[$index]['toestanden'];
        }
        unset($candidate);

        return $candidates;
    }

    /**
     * @param  array<int, object>  $candidates
     * @param  array<string, string>  $goUris
     * @return array<int, array<string, mixed>>
     */
    private function buildFindInOtherCaseCandidates(array $candidates, ?string $sourceGoUri, array $goUris): array
    {
        $uris = array_values(array_filter(array_map(fn (object $candidate): string => $candidate->rdf_uri, $candidates)));
        $toestandenByGoicUri = $this->fetchActiveToestandenByGoicUris($uris);
        $tbUris = [];
        foreach ($toestandenByGoicUri as $toestanden) {
            foreach ($toestanden as $toestand) {
                if (is_string($toestand['tb_rdf_uri'] ?? null)) {
                    $tbUris[] = $toestand['tb_rdf_uri'];
                }
            }
        }
        $tbDataByUri = $this->fetchTbDataByUris($tbUris);
        $tbAuditByUri = $this->fetchTbAuditMetaByUris($tbUris);

        return array_map(function (object $candidate) use ($toestandenByGoicUri, $tbDataByUri, $tbAuditByUri, $sourceGoUri, $goUris): array {
            $toestanden = array_map(function (array $toestand) use ($tbDataByUri, $tbAuditByUri): array {
                $tbUri = $toestand['tb_rdf_uri'];
                $audit = $tbAuditByUri[$tbUri] ?? [];

                return [
                    'tb_rdf_uri' => $tbUri,
                    'tb_class' => $toestand['tb_class'] ?? null,
                    'tb_data' => $tbDataByUri[$tbUri] ?? [],
                    'created_at' => $audit['created_at'] ?? null,
                ];
            }, $toestandenByGoicUri[$candidate->rdf_uri] ?? []);
            $candidateGoUri = $goUris[$candidate->rdf_uri] ?? null;

            return [
                'id' => (int) $candidate->id,
                'rdf_uri' => $candidate->rdf_uri,
                'case_id' => (int) $candidate->case_id,
                'case_soort_naam' => $candidate->case_soort_naam,
                'dossier_naam' => $candidate->dossier_naam,
                'same_go' => $sourceGoUri !== null && $sourceGoUri === $candidateGoUri,
                'go_uri' => $candidateGoUri,
                'toestanden' => $toestanden,
            ];
        }, $candidates);
    }

    /**
     * @param  array<int, int>  $allowedRechtsgrondIds
     */
    private function consultableCasesQuery(array $allowedRechtsgrondIds): Builder
    {
        $latestMutationByCase = DB::table('object_mutaties')
            ->join('transacties', 'transacties.id', '=', 'object_mutaties.transactie_id')
            ->selectRaw('transacties.case_id, MAX(object_mutaties.id) AS latest_mutatie_id')
            ->groupBy('transacties.case_id');

        return DB::table('cases')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->joinSub($latestMutationByCase, 'latest_case_mutaties', function ($join): void {
                $join->on('latest_case_mutaties.case_id', '=', 'cases.id');
            })
            ->join('object_mutaties as latest_mutatie', 'latest_mutatie.id', '=', 'latest_case_mutaties.latest_mutatie_id')
            ->join('transacties as latest_transactie', 'latest_transactie.id', '=', 'latest_mutatie.transactie_id')
            ->join('medewerkers as latest_medewerker', 'latest_medewerker.user_id', '=', 'latest_transactie.user_id')
            ->join('teams', 'teams.id', '=', 'latest_medewerker.team_id')
            ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
            ->select([
                'cases.id',
                'cases.uuid',
                'cases.case_soort_id',
                'cases.created_at',
                'case_soorten.naam as case_soort_naam',
                'case_soorten.code as case_soort_code',
                'latest_medewerker.team_id',
                'teams.naam as team_naam',
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function allowedRechtsgrondIdsForUser(int $userId): array
    {
        $ids = DB::table('medewerkers')
            ->join('functies', 'functies.medewerker_id', '=', 'medewerkers.id')
            ->join('autorisatie_rollen', 'autorisatie_rollen.functie_soort_id', '=', 'functies.functie_soort_id')
            ->where('medewerkers.user_id', $userId)
            ->whereNotNull('autorisatie_rollen.rechtsgrond_id')
            ->distinct()
            ->pluck('autorisatie_rollen.rechtsgrond_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return ! empty($ids) ? $ids : [-1];
    }

    /**
     * @param  array<string, array{is_kern_tb:bool,is_role_beschrijving:bool}>  $tbCapabilities
     */
    private function canFollowAsDependent(mixed $tbClass, array $tbCapabilities): bool
    {
        if (! is_string($tbClass) || $tbClass === '') {
            return false;
        }

        $capabilities = $tbCapabilities[$tbClass] ?? null;

        return is_array($capabilities)
            && ! ($capabilities['is_kern_tb'] ?? true)
            && ! ($capabilities['is_role_beschrijving'] ?? true);
    }

    private function buildIncidentRequirementTriple(string $dossierUri, bool $requiresIncidentInDossier): string
    {
        if (! $requiresIncidentInDossier) {
            return '';
        }

        return "<{$dossierUri}> <http://ontologie.politie.nl/def/vwm#vereistIncidentInDossier> true .";
    }

    private function buildDossiersOut(int $caseId, int $userId, array $allowedRechtsgrondIds): array
    {
        $dossiers = DB::table('dossiers')
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->get([
                'id',
                'naam',
                'rdf_uri',
                'parent_id',
                'created_at',
            ]);

        $dossierIds = $dossiers->pluck('id')->all();
        $goics = DB::table('gegevens_objecten_in_context')
            ->whereIn('dossier_id', $dossierIds)
            ->orderBy('id')
            ->get([
                'id',
                'rdf_uri',
                'dossier_id',
                'created_at',
            ]);

        $visibleGoicUris = $this->fetchVisibleGoicUrisForUser($allowedRechtsgrondIds);
        $activeVisibleGoicUris = $this->filterActiveGoicUris($visibleGoicUris);
        $goLinkMetaByUri = $this->fetchGoLinkMetaByGoicUris(
            $goics
                ->pluck('rdf_uri')
                ->filter(fn ($uri) => is_string($uri) && $uri !== '')
                ->values()
                ->all(),
            $activeVisibleGoicUris
        );

        $goicMap = [];
        foreach ($goics as $goic) {
            $goMeta = $goLinkMetaByUri[$goic->rdf_uri] ?? null;
            $goicMap[$goic->id] = [
                'id' => $goic->id,
                'rdf_uri' => $goic->rdf_uri,
                'dossier_id' => $goic->dossier_id,
                'go_uri' => is_array($goMeta) ? ($goMeta['go_uri'] ?? null) : null,
                'linked_goic_count' => is_array($goMeta) ? (int) ($goMeta['linked_goic_count'] ?? 0) : 0,
                'created_at' => $goic->created_at,
                'toestanden' => [],
            ];
        }
        $goicUriById = [];
        foreach ($goics as $goic) {
            $goicUriById[(int) $goic->id] = (string) $goic->rdf_uri;
        }
        $toestandenByGoicUri = $this->fetchActiveToestandenByGoicUris(array_values($goicUriById));
        $tbUris = [];
        foreach ($toestandenByGoicUri as $rows) {
            foreach ($rows as $row) {
                if (is_string($row['tb_rdf_uri'] ?? null) && ($row['tb_rdf_uri'] ?? '') !== '') {
                    $tbUris[] = $row['tb_rdf_uri'];
                }
            }
        }
        $tbDataByUri = $this->fetchTbDataByUris($tbUris);
        $tbAuditByUri = $this->fetchTbAuditMetaByUris($tbUris);

        foreach ($goicMap as $goicId => &$entry) {
            $goicUri = $goicUriById[(int) $goicId] ?? null;
            if (! is_string($goicUri) || $goicUri === '') {
                continue;
            }
            $rows = $toestandenByGoicUri[$goicUri] ?? [];
            foreach ($rows as $row) {
                $tbUri = $row['tb_rdf_uri'] ?? null;
                if (! is_string($tbUri) || $tbUri === '') {
                    continue;
                }
                $audit = $tbAuditByUri[$tbUri] ?? null;
                $entry['toestanden'][] = [
                    'mutatie_id' => is_array($audit) ? ($audit['mutatie_id'] ?? null) : null,
                    'sjabloon_uri' => is_array($audit) ? ($audit['sjabloon_uri'] ?? null) : ($row['tb_class'] ?? null),
                    'tb_id' => is_array($audit) ? ($audit['tb_id'] ?? null) : null,
                    'tb_rdf_uri' => $tbUri,
                    'tb_class' => $row['tb_class'] ?? null,
                    'tb_data' => $tbDataByUri[$tbUri] ?? null,
                    'created_at' => is_array($audit) ? ($audit['created_at'] ?? null) : null,
                ];
            }
        }
        unset($entry);

        $this->attachDependentStateInfo($goicMap, $allowedRechtsgrondIds);
        $this->attachFollowInfoToGoics($goicMap);

        $dossiersOut = [];
        foreach ($dossiers as $dossier) {
            $goicsForDossier = array_values(array_filter($goicMap, function ($item) use ($dossier) {
                return $item['dossier_id'] === $dossier->id;
            }));

            $dossiersOut[] = [
                'id' => $dossier->id,
                'naam' => $dossier->naam,
                'rdf_uri' => $dossier->rdf_uri,
                'parent_id' => $dossier->parent_id,
                'created_at' => $dossier->created_at,
                'goics' => $goicsForDossier,
            ];
        }

        return $dossiersOut;
    }

    /**
     * @param  array<int, array<string, mixed>>  $goicMap
     * @param  array<int, int>  $allowedRechtsgrondIds
     */
    private function attachDependentStateInfo(array &$goicMap, array $allowedRechtsgrondIds): void
    {
        $sourceTbUris = [];
        foreach ($goicMap as $goic) {
            foreach ($goic['toestanden'] ?? [] as $toestand) {
                $sourceTbUri = $this->referencedTbUri($toestand);
                if ($sourceTbUri !== null) {
                    $sourceTbUris[] = $sourceTbUri;
                }
            }
        }

        $sourceTbUris = array_values(array_unique($sourceTbUris));
        if ($sourceTbUris === []) {
            return;
        }

        $sourceMetaByTbUri = $this->fetchSourceTbMetadata($sourceTbUris);
        if ($sourceMetaByTbUri === []) {
            return;
        }

        $sourceGoicUris = array_values(array_unique(array_filter(array_column($sourceMetaByTbUri, 'goic_uri'))));
        $visibleSourceGoics = DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
            ->whereIn('gegevens_objecten_in_context.rdf_uri', $sourceGoicUris)
            ->get([
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
                'cases.id as case_id',
            ]);
        $sourceGoicByUri = [];
        foreach ($visibleSourceGoics as $sourceGoic) {
            $sourceGoicByUri[(string) $sourceGoic->goic_uri] = [
                'id' => (int) $sourceGoic->goic_id,
                'case_id' => (int) $sourceGoic->case_id,
            ];
        }

        $visibleSourceTbUris = array_keys(array_filter($sourceMetaByTbUri, fn (array $source): bool => isset($sourceGoicByUri[$source['goic_uri']])));
        $sourceTbDataByUri = $this->fetchTbDataByUris($visibleSourceTbUris);
        $sourceTbAuditByUri = $this->fetchTbAuditMetaByUris($visibleSourceTbUris);

        foreach ($goicMap as &$goic) {
            foreach ($goic['toestanden'] as &$toestand) {
                $sourceTbUri = $this->referencedTbUri($toestand);
                $sourceMeta = $sourceTbUri !== null ? ($sourceMetaByTbUri[$sourceTbUri] ?? null) : null;
                if (! is_array($sourceMeta)) {
                    continue;
                }

                $sourceGoic = $sourceGoicByUri[$sourceMeta['goic_uri']] ?? null;
                $sourceAudit = $sourceTbAuditByUri[$sourceTbUri] ?? null;
                $toestand['dependent_info'] = [
                    'is_dependent' => true,
                    'source_tb_uri' => $sourceTbUri,
                    'source_goic_uri' => $sourceMeta['goic_uri'],
                    'source_goic_id' => is_array($sourceGoic) ? $sourceGoic['id'] : null,
                    'source_case_id' => is_array($sourceGoic) ? $sourceGoic['case_id'] : null,
                    'source_target_class' => $sourceMeta['target_class'] ?? null,
                    'source_state' => is_array($sourceGoic) ? [
                        'mutatie_id' => is_array($sourceAudit) ? ($sourceAudit['mutatie_id'] ?? null) : null,
                        'sjabloon_uri' => is_array($sourceAudit) ? ($sourceAudit['sjabloon_uri'] ?? null) : $sourceMeta['tb_class'],
                        'tb_id' => is_array($sourceAudit) ? ($sourceAudit['tb_id'] ?? null) : null,
                        'tb_rdf_uri' => $sourceTbUri,
                        'tb_class' => $sourceMeta['tb_class'],
                        'tb_data' => $sourceTbDataByUri[$sourceTbUri] ?? null,
                        'created_at' => is_array($sourceAudit) ? ($sourceAudit['created_at'] ?? null) : null,
                    ] : null,
                ];
            }
            unset($toestand);
        }
        unset($goic);
    }

    private function referencedTbUri(array $toestand): ?string
    {
        $data = $toestand['tb_data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $property => $value) {
            if (! is_string($property) || ! str_ends_with($property, '#verwijstNaar')) {
                continue;
            }
            if (is_string($value) && $this->isAllowedHttpUri($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $sourceTbUris
     * @return array<string, array{goic_uri:string,tb_class:string,target_class:string|null}>
     */
    private function fetchSourceTbMetadata(array $sourceTbUris): array
    {
        $result = [];
        $graph = app(GraphService::class);
        foreach (array_chunk($sourceTbUris, 100) as $chunk) {
            $iriList = implode(' ', array_map(fn (string $uri): string => "<{$uri}>", $chunk));
            $rows = $graph->query("
                PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
                PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
                SELECT ?tb ?goic ?tbClass ?targetClass
                WHERE {
                    VALUES ?tb { {$iriList} }
                    ?tb a ?tbClass ; vwm:beschrijftGOIC ?goic .
                    OPTIONAL { ?goic vwm:heeftDoelClass ?targetClass . }
                    FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
                }
            ");
            foreach ($rows as $row) {
                $tbUri = $row['tb'] ?? null;
                $goicUri = $row['goic'] ?? null;
                $tbClass = $row['tbClass'] ?? null;
                $targetClass = $row['targetClass'] ?? null;
                if (! is_string($tbUri) || ! is_string($goicUri) || ! is_string($tbClass) || ! $this->isAllowedHttpUri($tbUri) || ! $this->isAllowedHttpUri($goicUri) || $tbClass === '') {
                    continue;
                }
                $result[$tbUri] = [
                    'goic_uri' => $goicUri,
                    'tb_class' => $tbClass,
                    'target_class' => is_string($targetClass) && $targetClass !== '' ? $targetClass : null,
                ];
            }
        }

        return $result;
    }

    /** @return array<string, true> */
    private function fetchFollowedSourceTbUris(?string $targetGoicUri): array
    {
        if (! is_string($targetGoicUri) || ! $this->isAllowedHttpUri($targetGoicUri)) {
            return [];
        }

        $rows = app(GraphService::class)->query("
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?sourceTb
            WHERE {
                ?dependent a vwm:AfhankelijkeTB ;
                    vwm:beschrijftGOIC <{$targetGoicUri}> ;
                    vwm:verwijstNaar ?sourceTb .
                FILTER NOT EXISTS { ?dependent dpm:invalidatedAtTime ?invalidatedAt . }
            }
        ");

        $result = [];
        foreach ($rows as $row) {
            $sourceTbUri = $row['sourceTb'] ?? null;
            if (is_string($sourceTbUri) && $this->isAllowedHttpUri($sourceTbUri)) {
                $result[$sourceTbUri] = true;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $goicMap
     */
    private function attachFollowInfoToGoics(array &$goicMap): void
    {
        if (empty($goicMap)) {
            return;
        }

        $goicUris = [];
        foreach ($goicMap as $item) {
            $uri = $item['rdf_uri'] ?? null;
            if (is_string($uri) && $uri !== '') {
                $goicUris[] = $uri;
            }
        }

        if (empty($goicUris)) {
            return;
        }

        $assocByOwnedUri = $this->fetchFollowAssociationsByOwnedGoics($goicUris);
        if (empty($assocByOwnedUri)) {
            return;
        }

        $targetUris = array_values(array_unique(array_filter(array_map(function ($assoc) {
            return $assoc['target_goic_uri'] ?? null;
        }, $assocByOwnedUri))));

        if (empty($targetUris)) {
            return;
        }

        $targetGoics = DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->whereIn('gegevens_objecten_in_context.rdf_uri', $targetUris)
            ->get([
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
                'cases.id as case_id',
            ]);

        $targetByUri = [];
        foreach ($targetGoics as $row) {
            $targetByUri[$row->goic_uri] = [
                'id' => (int) $row->goic_id,
                'case_id' => (int) $row->case_id,
            ];
        }

        $targetGoicIds = array_values(array_unique(array_map(fn ($row) => (int) $row->goic_id, $targetGoics->all())));
        $latestByGoicId = $this->fetchPreferredToestandByGoicIds($targetGoicIds);
        $sourceTbDataByUri = $this->fetchTbDataByUris(
            array_values(array_filter(array_map(function ($item) {
                return $item['tb_rdf_uri'] ?? null;
            }, $latestByGoicId)))
        );

        foreach ($goicMap as &$goic) {
            $ownedUri = $goic['rdf_uri'] ?? null;
            if (! is_string($ownedUri) || $ownedUri === '' || empty($assocByOwnedUri[$ownedUri])) {
                continue;
            }

            $assoc = $assocByOwnedUri[$ownedUri];
            $targetUri = $assoc['target_goic_uri'] ?? null;
            if (! is_string($targetUri) || $targetUri === '') {
                continue;
            }

            $targetInfo = $targetByUri[$targetUri] ?? null;
            $targetGoicId = is_array($targetInfo) ? ($targetInfo['id'] ?? null) : null;
            $latest = is_int($targetGoicId) ? ($latestByGoicId[$targetGoicId] ?? null) : null;

            $sourceState = null;
            if (is_array($latest)) {
                $sourceTbUri = $latest['tb_rdf_uri'] ?? null;
                $sourceState = [
                    'mutatie_id' => $latest['mutatie_id'] ?? null,
                    'sjabloon_uri' => $latest['tb_class'] ?? null,
                    'tb_id' => null,
                    'tb_class' => $latest['tb_class'] ?? null,
                    'tb_rdf_uri' => $sourceTbUri,
                    'tb_data' => is_string($sourceTbUri) ? ($sourceTbDataByUri[$sourceTbUri] ?? null) : null,
                    'created_at' => $latest['created_at'] ?? null,
                ];
            }

            $goic['follow_info'] = [
                'is_followed' => true,
                'association_uri' => $assoc['association_uri'] ?? null,
                'source_goic_uri' => $targetUri,
                'source_goic_id' => is_array($targetInfo) ? ($targetInfo['id'] ?? null) : null,
                'source_case_id' => is_array($targetInfo) ? ($targetInfo['case_id'] ?? null) : null,
                'source_state' => $sourceState,
            ];
        }
        unset($goic);
    }

    /**
     * @param  array<int, string>  $ownedGoicUris
     * @return array<string, array<string, string>>
     */
    private function fetchFollowAssociationsByOwnedGoics(array $ownedGoicUris): array
    {
        $uris = array_values(array_unique(array_filter($ownedGoicUris, function ($uri) {
            return is_string($uri) && $uri !== '';
        })));

        if (empty($uris)) {
            return [];
        }

        $result = [];
        $graph = app(GraphService::class);
        foreach (array_chunk($uris, 100) as $chunk) {
            $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $chunk));
            $query = "
                PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
                SELECT ?assoc ?owned ?target
                WHERE {
                    VALUES ?owned { {$iriList} }
                    ?assoc a dpm:DataObjectAssociation ;
                           dpm:ownedObject ?owned ;
                           dpm:targetObject ?target .
                    FILTER NOT EXISTS { ?assoc dpm:invalidatedAtTime ?invalidatedAtTime . }
                }
            ";

            try {
                $rows = $graph->query($query);
            } catch (\Throwable $e) {
                logger()->warning('Kon follow-associaties niet uit GraphDB lezen', [
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($rows as $row) {
                $owned = $row['owned'] ?? null;
                $target = $row['target'] ?? null;
                if (! is_string($owned) || $owned === '' || ! is_string($target) || $target === '') {
                    continue;
                }

                $result[$owned] = [
                    'association_uri' => is_string($row['assoc'] ?? null) ? $row['assoc'] : '',
                    'target_goic_uri' => $target,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $goicIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchPreferredToestandByGoicIds(array $goicIds): array
    {
        $ids = array_values(array_unique(array_filter($goicIds, fn ($id) => is_int($id) && $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $goics = DB::table('gegevens_objecten_in_context')
            ->whereIn('id', $ids)
            ->get(['id', 'rdf_uri']);

        $idByUri = [];
        foreach ($goics as $goic) {
            if (is_string($goic->rdf_uri) && $goic->rdf_uri !== '') {
                $idByUri[$goic->rdf_uri] = (int) $goic->id;
            }
        }

        $toestandenByGoicUri = $this->fetchActiveToestandenByGoicUris(array_keys($idByUri));
        $tbUris = [];
        foreach ($toestandenByGoicUri as $rows) {
            foreach ($rows as $row) {
                if (is_string($row['tb_rdf_uri'] ?? null) && ($row['tb_rdf_uri'] ?? '') !== '') {
                    $tbUris[] = $row['tb_rdf_uri'];
                }
            }
        }
        $auditByTbUri = $this->fetchTbAuditMetaByUris($tbUris);
        $result = [];

        foreach ($toestandenByGoicUri as $goicUri => $rows) {
            $goicId = $idByUri[$goicUri] ?? null;
            if (! is_int($goicId) || $goicId <= 0) {
                continue;
            }

            $sorted = $rows;
            usort($sorted, function (array $a, array $b) use ($auditByTbUri) {
                $aTb = $a['tb_rdf_uri'] ?? '';
                $bTb = $b['tb_rdf_uri'] ?? '';
                $aAudit = is_string($aTb) ? ($auditByTbUri[$aTb] ?? null) : null;
                $bAudit = is_string($bTb) ? ($auditByTbUri[$bTb] ?? null) : null;
                $aCreated = (string) (is_array($aAudit) ? ($aAudit['created_at'] ?? '') : '');
                $bCreated = (string) (is_array($bAudit) ? ($bAudit['created_at'] ?? '') : '');

                return $bCreated <=> $aCreated;
            });

            $preferred = null;
            $fallback = null;
            foreach ($sorted as $row) {
                $tbClass = (string) ($row['tb_class'] ?? '');
                $isRoleClass = $tbClass !== '' && stripos($tbClass, 'rol') !== false;
                if ($fallback === null) {
                    $fallback = $row;
                }
                if (! $isRoleClass) {
                    $preferred = $row;
                    break;
                }
            }

            $selected = $preferred ?? $fallback;
            if (! is_array($selected)) {
                continue;
            }

            $tbUri = $selected['tb_rdf_uri'] ?? null;
            $audit = is_string($tbUri) ? ($auditByTbUri[$tbUri] ?? null) : null;
            $result[$goicId] = [
                'mutatie_id' => is_array($audit) ? ($audit['mutatie_id'] ?? null) : null,
                'created_at' => is_array($audit) ? ($audit['created_at'] ?? null) : null,
                'tb_rdf_uri' => $tbUri,
                'tb_class' => $selected['tb_class'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $goicUris
     * @return array<string, array<int, array{tb_rdf_uri:string,tb_class:string|null}>>
     */
    private function fetchActiveToestandenByGoicUris(array $goicUris): array
    {
        $uris = array_values(array_unique(array_filter($goicUris, fn ($uri) => is_string($uri) && $uri !== '')));
        if (empty($uris)) {
            return [];
        }

        $result = [];
        $graph = app(GraphService::class);
        foreach (array_chunk($uris, 100) as $chunk) {
            $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $chunk));
            $query = "
                PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
                PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
                SELECT DISTINCT ?goic ?tb ?tbClass
                WHERE {
                    VALUES ?goic { {$iriList} }
                    {
                        ?tb vwm:beschrijftGOIC ?goic .
                    }
                    UNION
                    {
                        ?mutatie a vwm:ObjectMutatie ;
                                 vwm:heeftBetrekkingOp ?goic ;
                                 vwm:produceert ?tb .
                    }
                    ?tb rdf:type ?tbClass .
                    ?tbClass rdfs:subClassOf+ vwm:ToestandsBeschrijving .
                    FILTER (?tbClass != vwm:ToestandsBeschrijving)
                    FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
                }
                ORDER BY ?goic ?tb
            ";

            try {
                $rows = $graph->query($query);
            } catch (\Throwable $e) {
                logger()->warning('Kon actieve toestanden niet uit GraphDB lezen', [
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($rows as $row) {
                $goicUri = $row['goic'] ?? null;
                $tbUri = $row['tb'] ?? null;
                $tbClass = $row['tbClass'] ?? null;
                if (! is_string($goicUri) || $goicUri === '' || ! is_string($tbUri) || $tbUri === '') {
                    continue;
                }
                if (! isset($result[$goicUri])) {
                    $result[$goicUri] = [];
                }
                $result[$goicUri][] = [
                    'tb_rdf_uri' => $tbUri,
                    'tb_class' => is_string($tbClass) ? $tbClass : null,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $tbUris
     * @return array<string, array<string, mixed>>
     */
    private function fetchTbAuditMetaByUris(array $tbUris): array
    {
        $uris = array_values(array_unique(array_filter($tbUris, fn ($uri) => is_string($uri) && $uri !== '')));
        if (empty($uris)) {
            return [];
        }

        $rows = DB::table('object_mutaties')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->whereIn('toestands_beschrijvingen.rdf_uri', $uris)
            ->orderByDesc('object_mutaties.id')
            ->get([
                'object_mutaties.id as mutatie_id',
                'object_mutaties.sjabloon_uri',
                'object_mutaties.created_at',
                'toestands_beschrijvingen.id as tb_id',
                'toestands_beschrijvingen.rdf_uri as tb_rdf_uri',
            ]);

        $result = [];
        foreach ($rows as $row) {
            $tbUri = $row->tb_rdf_uri ?? null;
            if (! is_string($tbUri) || $tbUri === '' || isset($result[$tbUri])) {
                continue;
            }
            $result[$tbUri] = [
                'mutatie_id' => (int) ($row->mutatie_id ?? 0),
                'sjabloon_uri' => $row->sjabloon_uri,
                'created_at' => $row->created_at,
                'tb_id' => $row->tb_id,
            ];
        }

        return $result;
    }

    private function fetchGoLinkMetaByGoicUris(array $goicUris, array $visibleGoicUris): array
    {
        $uris = array_values(array_unique(array_filter($goicUris, function ($uri) {
            return is_string($uri) && $uri !== '';
        })));

        if (empty($uris)) {
            return [];
        }

        $graph = app(GraphService::class);
        $result = [];
        $visibleGoicLookup = array_fill_keys(array_values(array_unique($visibleGoicUris)), true);
        if (empty($visibleGoicLookup)) {
            return [];
        }

        foreach (array_chunk($uris, 100) as $chunk) {
            $iriList = implode(' ', array_map(function ($uri) {
                return "<{$uri}>";
            }, $chunk));

            $query = "
                PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
                SELECT ?goic ?go ?otherGoic
                WHERE {
                    VALUES ?goic { {$iriList} }
                    ?goic vwm:beschrijftGO ?go .
                    ?otherGoic vwm:beschrijftGO ?go .
                }
            ";

            try {
                $rows = $graph->query($query);
            } catch (\Throwable $e) {
                logger()->warning('Kon GO-linkmeta niet uit GraphDB lezen', [
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $countBuffer = [];
            foreach ($rows as $row) {
                $goicUri = $row['goic'] ?? null;
                $goUri = $row['go'] ?? null;
                $otherGoic = $row['otherGoic'] ?? null;
                if (! is_string($goicUri) || $goicUri === '' || ! is_string($goUri) || $goUri === '' || ! is_string($otherGoic) || $otherGoic === '') {
                    continue;
                }

                if (! isset($visibleGoicLookup[$otherGoic])) {
                    continue;
                }

                if (! isset($countBuffer[$goicUri])) {
                    $countBuffer[$goicUri] = [
                        'go_uri' => $goUri,
                        'others' => [],
                    ];
                }

                $countBuffer[$goicUri]['others'][$otherGoic] = true;
            }

            foreach ($countBuffer as $goicUri => $meta) {
                $result[$goicUri] = [
                    'go_uri' => $meta['go_uri'],
                    'linked_goic_count' => count($meta['others']),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $allowedRechtsgrondIds
     * @return array<int, string>
     */
    private function fetchVisibleGoicUrisForUser(array $allowedRechtsgrondIds): array
    {
        return DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)
            ->pluck('gegevens_objecten_in_context.rdf_uri')
            ->filter(fn ($uri) => is_string($uri) && $uri !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $goicUris
     * @return array<int, string>
     */
    private function filterActiveGoicUris(array $goicUris): array
    {
        $uris = array_values(array_unique(array_filter($goicUris, fn ($uri) => is_string($uri) && $uri !== '')));
        if (empty($uris)) {
            return [];
        }

        $active = [];
        foreach ($this->fetchActiveToestandenByGoicUris($uris) as $goicUri => $rows) {
            if (! empty($rows)) {
                $active[(string) $goicUri] = true;
            }
        }

        $activeFollowUris = DB::table('data_object_associations')
            ->whereIn('owned_goic_uri', $uris)
            ->whereNull('invalidated_at')
            ->pluck('owned_goic_uri')
            ->all();

        foreach ($activeFollowUris as $uri) {
            if (is_string($uri) && $uri !== '') {
                $active[$uri] = true;
            }
        }

        return array_values(array_intersect($uris, array_keys($active)));
    }

    private function fetchGoicUrisByGoUri(string $goUri): array
    {
        if (! $this->isAllowedHttpUri($goUri)) {
            return [];
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?goic
            WHERE {
                ?goic vwm:beschrijftGO <{$goUri}> .
            }
            ORDER BY ?goic
        ";

        try {
            $rows = app(GraphService::class)->query($query);
        } catch (\Throwable $e) {
            logger()->warning('Kon GOICs voor GO niet uit GraphDB lezen', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $uris = [];
        foreach ($rows as $row) {
            $goicUri = $row['goic'] ?? null;
            if (is_string($goicUri) && $goicUri !== '') {
                $uris[] = $goicUri;
            }
        }

        return array_values(array_unique($uris));
    }

    private function isAllowedHttpUri(string $uri): bool
    {
        return (bool) preg_match('/^https?:\/\/[^\s<>"\']+$/', $uri);
    }

    private function fetchTbDataByUris(array $tbUris): array
    {
        $uris = array_values(array_unique(array_filter($tbUris, function ($uri) {
            return is_string($uri) && $uri !== '';
        })));

        if (empty($uris)) {
            return [];
        }

        $graph = app(GraphService::class);
        $result = [];
        $excludePredicates = [
            'http://www.w3.org/1999/02/22-rdf-syntax-ns#type',
            'http://ontologie.politie.nl/def/vwm#beschrijftGOIC',
            'http://ontologie.politie.nl/def/vwm#geregistreerdOp',
        ];
        $excludeFilter = implode(' && ', array_map(function ($predicate) {
            return "?p != <{$predicate}>";
        }, $excludePredicates));

        foreach (array_chunk($uris, 100) as $chunk) {
            $iriList = implode(' ', array_map(function ($uri) {
                return "<{$uri}>";
            }, $chunk));

            $query = "
                SELECT ?tb ?p ?o
                WHERE {
                    VALUES ?tb { {$iriList} }
                    ?tb ?p ?o .
                    FILTER({$excludeFilter})
                }
                ORDER BY ?tb ?p ?o
            ";

            try {
                $rows = $graph->query($query);
            } catch (\Throwable $e) {
                logger()->warning('Kon TB-data niet uit GraphDB lezen', [
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($rows as $row) {
                $tbUri = $row['tb'] ?? null;
                $property = $row['p'] ?? null;
                $value = $row['o'] ?? null;

                if (! is_string($tbUri) || $tbUri === '' || ! is_string($property) || $property === '' || $value === null) {
                    continue;
                }

                if (! isset($result[$tbUri])) {
                    $result[$tbUri] = [];
                }

                if (! array_key_exists($property, $result[$tbUri])) {
                    $result[$tbUri][$property] = $value;

                    continue;
                }

                $existing = $result[$tbUri][$property];
                if (is_array($existing)) {
                    if (! in_array($value, $existing, true)) {
                        $existing[] = $value;
                    }
                    $result[$tbUri][$property] = $existing;

                    continue;
                }

                if ($existing !== $value) {
                    $result[$tbUri][$property] = [$existing, $value];
                }
            }
        }

        return $result;
    }

    private function purgeEmptyCasesForUser(int $userId): void
    {
        $emptyCases = DB::table('cases')
            ->where('cases.user_id', $userId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transacties')
                    ->whereColumn('transacties.case_id', 'cases.id');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('dossiers')
                    ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.dossier_id', '=', 'dossiers.id')
                    ->whereColumn('dossiers.case_id', 'cases.id');
            })
            ->get(['cases.id']);

        if ($emptyCases->isEmpty()) {
            return;
        }

        foreach ($emptyCases as $case) {
            $caseId = (int) ($case->id ?? 0);
            if ($caseId <= 0) {
                continue;
            }

            DB::transaction(function () use ($caseId) {
                $dossiers = DB::table('dossiers')
                    ->where('case_id', $caseId)
                    ->get(['id', 'rdf_uri']);

                $dossierUris = $dossiers
                    ->pluck('rdf_uri')
                    ->filter(fn ($uri) => is_string($uri) && $uri !== '')
                    ->values()
                    ->all();

                if (! empty($dossierUris)) {
                    try {
                        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $dossierUris));
                        app(GraphService::class)->update("
                            DELETE {
                                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                                    ?d ?p ?o .
                                    ?s ?p2 ?d .
                                }
                            }
                            WHERE {
                                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                                    VALUES ?d { {$iriList} }
                                    OPTIONAL { ?d ?p ?o . }
                                    OPTIONAL { ?s ?p2 ?d . }
                                }
                            }
                        ");
                    } catch (\Throwable $e) {
                        logger()->warning('Kon lege case niet volledig uit GraphDB opschonen', [
                            'case_id' => $caseId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                DB::table('dossiers')->where('case_id', $caseId)->delete();
                DB::table('cases')->where('id', $caseId)->delete();
            });
        }
    }
}
