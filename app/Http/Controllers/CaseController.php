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
            $dossiers = DB::table('dossiers')
                ->where('case_id', $activeCase->id)
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

            $goicIds = $goics->pluck('id')->all();
            $mutaties = [];
            if (! empty($goicIds)) {
                $mutaties = DB::table('object_mutaties')
                    ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                    ->whereIn('object_mutaties.gegevens_object_in_context_id', $goicIds)
                    ->orderBy('object_mutaties.created_at')
                    ->get([
                        'object_mutaties.id',
                        'object_mutaties.gegevens_object_in_context_id as goic_id',
                        'object_mutaties.sjabloon_uri',
                        'object_mutaties.data',
                        'object_mutaties.created_at',
                        'toestands_beschrijvingen.id as tb_id',
                        'toestands_beschrijvingen.rdf_uri as tb_rdf_uri',
                        'toestands_beschrijvingen.beschrijving as tb_class',
                        'toestands_beschrijvingen.toestand_data as tb_data',
                    ]);
            }

            $goicMap = [];
            foreach ($goics as $goic) {
                $goicMap[$goic->id] = [
                    'id' => $goic->id,
                    'rdf_uri' => $goic->rdf_uri,
                    'dossier_id' => $goic->dossier_id,
                    'created_at' => $goic->created_at,
                    'toestanden' => [],
                ];
            }

            foreach ($mutaties as $row) {
                if (empty($row->goic_id) || empty($goicMap[$row->goic_id])) {
                    continue;
                }
                $tbData = null;
                if (! empty($row->tb_data)) {
                    $decoded = json_decode($row->tb_data, true);
                    $tbData = json_last_error() === JSON_ERROR_NONE ? $decoded : $row->tb_data;
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
        }

        return Inertia::render('cases/Consult', [
            'cases' => $cases,
            'activeCase' => $activeCase,
            'dossiers' => $dossiersOut,
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

        $goicIds = $goics->pluck('id')->all();
        $mutaties = [];
        if (! empty($goicIds)) {
            $mutaties = DB::table('object_mutaties')
                ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                ->whereIn('object_mutaties.gegevens_object_in_context_id', $goicIds)
                ->orderBy('object_mutaties.created_at')
                ->get([
                    'object_mutaties.id',
                    'object_mutaties.gegevens_object_in_context_id as goic_id',
                    'object_mutaties.sjabloon_uri',
                    'object_mutaties.data',
                    'object_mutaties.created_at',
                    'toestands_beschrijvingen.id as tb_id',
                    'toestands_beschrijvingen.rdf_uri as tb_rdf_uri',
                    'toestands_beschrijvingen.beschrijving as tb_class',
                    'toestands_beschrijvingen.toestand_data as tb_data',
                ]);
        }

        $goicMap = [];
        foreach ($goics as $goic) {
            $goicMap[$goic->id] = [
                'id' => $goic->id,
                'rdf_uri' => $goic->rdf_uri,
                'dossier_id' => $goic->dossier_id,
                'created_at' => $goic->created_at,
                'toestanden' => [],
            ];
        }

        foreach ($mutaties as $row) {
            if (empty($row->goic_id) || empty($goicMap[$row->goic_id])) {
                continue;
            }
            $tbData = null;
            if (! empty($row->tb_data)) {
                $decoded = json_decode($row->tb_data, true);
                $tbData = json_last_error() === JSON_ERROR_NONE ? $decoded : $row->tb_data;
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
}
