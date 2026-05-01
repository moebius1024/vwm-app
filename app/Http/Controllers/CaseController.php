<?php

namespace App\Http\Controllers;

use App\Services\GraphService;
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
        $userId = $request->user()->id;

        $caseSoorten = DB::table('case_soorten')
            ->select('id', 'naam', 'code')
            ->orderBy('naam')
            ->get();

        $cases = DB::table('cases')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('cases.user_id', $userId)
            ->orderByDesc('cases.created_at')
            ->get([
                'cases.id',
                'cases.uuid',
                'cases.created_at',
                'case_soorten.naam as case_soort_naam',
                'case_soorten.code as case_soort_code',
            ]);

        return Inertia::render('cases/Start', [
            'caseSoorten' => $caseSoorten,
            'cases' => $cases,
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

        $caseId = DB::transaction(function () use ($validated, $request) {
            $caseSoort = DB::table('case_soorten')
                ->select('naam')
                ->where('id', $validated['case_soort_id'])
                ->first();

            $caseId = DB::table('cases')->insertGetId([
                'uuid' => (string) Str::uuid(),
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

            return $caseId;
        });

        return redirect()->route('cases.edit', ['case' => $caseId]);
    }

    /**
     * Bewerken: registreer + raadpleeg binnen dezelfde case.
     */
    public function edit(Request $request): Response
    {
        $caseId = $request->integer('case');
        $case = null;

        if ($caseId) {
            $case = DB::table('cases')
                ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
                ->where('cases.id', $caseId)
                ->where('cases.user_id', $request->user()->id)
                ->first([
                    'cases.id',
                    'cases.uuid',
                    'cases.case_soort_id',
                    'case_soorten.naam as case_soort_naam',
                    'case_soorten.code as case_soort_code',
                ]);
        }

        if (! $case && $caseId) {
            return redirect('/start');
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
            $dossiersOut = $this->buildDossiersOut($case->id);
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
    public function consult(Request $request): Response
    {
        $userId = $request->user()->id;
        $caseId = $request->integer('case');
        $followTargetCaseId = $request->integer('follow_target_case');
        $goUri = trim((string) $request->query('go', ''));

        $cases = DB::table('cases')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('cases.user_id', $userId)
            ->orderByDesc('cases.created_at')
            ->get([
                'cases.id',
                'cases.uuid',
                'cases.created_at',
                'case_soorten.naam as case_soort_naam',
                'case_soorten.code as case_soort_code',
            ]);

        $activeCase = null;
        $dossiersOut = [];

        if ($caseId) {
            $activeCase = $cases->firstWhere('id', $caseId);
        }

        if ($activeCase) {
            $dossiersOut = $this->buildDossiersOut((int) $activeCase->id);
        }

        return Inertia::render('cases/Consult', [
            'cases' => $cases,
            'activeCase' => $activeCase,
            'dossiers' => $dossiersOut,
            'followTargetCaseId' => $followTargetCaseId ?: null,
            'goUri' => $this->isAllowedHttpUri($goUri) ? $goUri : null,
        ]);
    }

    public function consultGo(Request $request): Response
    {
        $validated = $request->validate([
            'go' => ['required', 'string'],
            'case' => ['nullable', 'integer'],
        ]);

        $goUri = trim((string) ($validated['go'] ?? ''));
        $selectedCaseId = isset($validated['case']) ? (int) $validated['case'] : null;

        if (! $this->isAllowedHttpUri($goUri)) {
            abort(404);
        }

        $userId = $request->user()->id;
        $goicUris = $this->fetchGoicUrisByGoUri($goUri);
        $goics = collect();

        if (! empty($goicUris)) {
            $goics = DB::table('gegevens_objecten_in_context')
                ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
                ->join('cases', 'cases.id', '=', 'dossiers.case_id')
                ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
                ->where('cases.user_id', $userId)
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

        $goicIds = $goics->pluck('goic_id')->values()->all();
        $mutaties = collect();
        if (! empty($goicIds)) {
            $mutaties = DB::table('object_mutaties')
                ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                ->whereIn('object_mutaties.gegevens_object_in_context_id', $goicIds)
                ->orderBy('object_mutaties.created_at')
                ->get([
                    'object_mutaties.id',
                    'object_mutaties.gegevens_object_in_context_id as goic_id',
                    'object_mutaties.sjabloon_uri',
                    'object_mutaties.created_at',
                    'toestands_beschrijvingen.id as tb_id',
                    'toestands_beschrijvingen.rdf_uri as tb_rdf_uri',
                    'toestands_beschrijvingen.beschrijving as tb_class',
                ]);
        }

        $tbDataByUri = $this->fetchTbDataByUris(
            $mutaties
                ->pluck('tb_rdf_uri')
                ->filter(fn ($uri) => is_string($uri) && $uri !== '')
                ->values()
                ->all()
        );

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

        foreach ($mutaties as $row) {
            $goicId = (int) ($row->goic_id ?? 0);
            if ($goicId <= 0 || empty($goicMap[$goicId])) {
                continue;
            }

            $tbData = null;
            if (! empty($row->tb_rdf_uri) && is_string($row->tb_rdf_uri)) {
                $tbData = $tbDataByUri[$row->tb_rdf_uri] ?? null;
            }

            $goicMap[$goicId]['toestanden'][] = [
                'mutatie_id' => $row->id,
                'sjabloon_uri' => $row->sjabloon_uri,
                'tb_id' => $row->tb_id,
                'tb_rdf_uri' => $row->tb_rdf_uri,
                'tb_class' => $row->tb_class,
                'tb_data' => $tbData,
                'created_at' => $row->created_at,
            ];
        }

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
            'goics' => $goicsOut,
        ]);
    }

    private function buildDossiersOut(int $caseId): array
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

        $goLinkMetaByUri = $this->fetchGoLinkMetaByGoicUris(
            $goics
                ->pluck('rdf_uri')
                ->filter(fn ($uri) => is_string($uri) && $uri !== '')
                ->values()
                ->all()
        );

        $goicIds = $goics->pluck('id')->all();
        $mutaties = collect();
        if (! empty($goicIds)) {
            $mutaties = DB::table('object_mutaties')
                ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                ->whereIn('object_mutaties.gegevens_object_in_context_id', $goicIds)
                ->orderBy('object_mutaties.created_at')
                ->get([
                    'object_mutaties.id',
                    'object_mutaties.gegevens_object_in_context_id as goic_id',
                    'object_mutaties.sjabloon_uri',
                    'object_mutaties.created_at',
                    'toestands_beschrijvingen.id as tb_id',
                    'toestands_beschrijvingen.rdf_uri as tb_rdf_uri',
                    'toestands_beschrijvingen.beschrijving as tb_class',
                ]);
        }

        $tbDataByUri = $this->fetchTbDataByUris(
            $mutaties
                ->pluck('tb_rdf_uri')
                ->filter(fn ($uri) => is_string($uri) && $uri !== '')
                ->values()
                ->all()
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

        foreach ($mutaties as $row) {
            if (empty($row->goic_id) || empty($goicMap[$row->goic_id])) {
                continue;
            }

            $tbData = null;
            if (! empty($row->tb_rdf_uri) && is_string($row->tb_rdf_uri)) {
                $tbData = $tbDataByUri[$row->tb_rdf_uri] ?? null;
            }

            $goicMap[$row->goic_id]['toestanden'][] = [
                'mutatie_id' => $row->id,
                'sjabloon_uri' => $row->sjabloon_uri,
                'tb_id' => $row->tb_id,
                'tb_rdf_uri' => $row->tb_rdf_uri,
                'tb_class' => $row->tb_class,
                'tb_data' => $tbData,
                'created_at' => $row->created_at,
            ];
        }

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

        $rows = DB::table('object_mutaties')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->whereIn('object_mutaties.gegevens_object_in_context_id', $ids)
            ->orderByDesc('object_mutaties.created_at')
            ->orderByDesc('object_mutaties.id')
            ->get([
                'object_mutaties.id as mutatie_id',
                'object_mutaties.gegevens_object_in_context_id as goic_id',
                'object_mutaties.created_at',
                'toestands_beschrijvingen.rdf_uri as tb_rdf_uri',
                'toestands_beschrijvingen.beschrijving as tb_class',
            ]);

        $result = [];
        $fallback = [];
        foreach ($rows as $row) {
            $goicId = (int) ($row->goic_id ?? 0);
            if ($goicId <= 0) {
                continue;
            }

            $entry = [
                'mutatie_id' => (int) ($row->mutatie_id ?? 0),
                'created_at' => $row->created_at,
                'tb_rdf_uri' => $row->tb_rdf_uri,
                'tb_class' => $row->tb_class,
            ];

            if (! isset($fallback[$goicId])) {
                $fallback[$goicId] = $entry;
            }

            if (isset($result[$goicId])) {
                continue;
            }

            $tbClass = (string) ($row->tb_class ?? '');
            $isRoleClass = $tbClass !== '' && stripos($tbClass, 'rol') !== false;

            if ($isRoleClass) {
                continue;
            }

            $result[$goicId] = $entry;
        }

        foreach ($fallback as $goicId => $entry) {
            if (! isset($result[$goicId])) {
                $result[$goicId] = $entry;
            }
        }

        return $result;
    }

    private function fetchGoLinkMetaByGoicUris(array $goicUris): array
    {
        $uris = array_values(array_unique(array_filter($goicUris, function ($uri) {
            return is_string($uri) && $uri !== '';
        })));

        if (empty($uris)) {
            return [];
        }

        $graph = app(GraphService::class);
        $result = [];

        foreach (array_chunk($uris, 100) as $chunk) {
            $iriList = implode(' ', array_map(function ($uri) {
                return "<{$uri}>";
            }, $chunk));

            $query = "
                PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
                SELECT ?goic ?go (COUNT(DISTINCT ?otherGoic) AS ?linkedCount)
                WHERE {
                    VALUES ?goic { {$iriList} }
                    ?goic vwm:beschrijftGO ?go .
                    ?otherGoic vwm:beschrijftGO ?go .
                }
                GROUP BY ?goic ?go
            ";

            try {
                $rows = $graph->query($query);
            } catch (\Throwable $e) {
                logger()->warning('Kon GO-linkmeta niet uit GraphDB lezen', [
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($rows as $row) {
                $goicUri = $row['goic'] ?? null;
                $goUri = $row['go'] ?? null;
                if (! is_string($goicUri) || $goicUri === '' || ! is_string($goUri) || $goUri === '') {
                    continue;
                }

                $result[$goicUri] = [
                    'go_uri' => $goUri,
                    'linked_goic_count' => (int) ($row['linkedCount'] ?? 0),
                ];
            }
        }

        return $result;
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
}
