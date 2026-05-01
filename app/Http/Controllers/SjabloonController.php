<?php

namespace App\Http\Controllers;

use App\Services\GraphService;
use App\Services\SjabloonMetadataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SjabloonController extends Controller
{
    protected $graphService;

    protected $metadataService;

    public function __construct(GraphService $graphService, SjabloonMetadataService $metadataService)
    {
        $this->graphService = $graphService;
        $this->metadataService = $metadataService;
    }

    /**
     * Haalt de UI-definitie op uit de Graph op basis van de SQLite ID.
     */
    public function getSjabloonVoorTransactie($transactieSoortId)
    {
        $ts = DB::table('transactie_soorten')->find($transactieSoortId);

        if (! $ts) {
            return response()->json(['error' => 'TransactieSoort niet gevonden'], 404);
        }

        $linkedSjablonen = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'sjabloon')
            ->orderBy('volgorde')
            ->get(['sjabloon_uri', 'volgorde'])
            ->all();

        $allowed = [];
        $primarySjabloon = null;

        foreach ($linkedSjablonen as $row) {
            $uri = $row->sjabloon_uri;
            $info = $this->metadataService->fetchSjabloon($uri);
            $allowed[] = [
                'sjabloon_uri' => $uri,
                'label' => $info['sjabloon_label'],
                'target_class' => $info['target_class'],
                'volgorde' => $row->volgorde ?? 1,
            ];

            if ($primarySjabloon === null) {
                $primarySjabloon = $info;
                $primarySjabloon['sjabloon_uri'] = $uri;
            }
        }

        $linkedRoles = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'rol')
            ->orderBy('volgorde')
            ->get(['sjabloon_uri', 'volgorde'])
            ->all();

        $roleUris = array_map(fn ($row) => $row->sjabloon_uri, $linkedRoles);
        $roleMeta = $this->metadataService->fetchRolTbMetaByClasses($roleUris);
        $roleShapeRules = $this->metadataService->fetchRoleShapeRules();
        $allowedRoles = [];
        foreach ($linkedRoles as $row) {
            $selectorUri = $row->sjabloon_uri;
            $meta = $roleMeta[$selectorUri] ?? null;
            $resolvedRoleType = null;

            if (! $meta) {
                $resolved = $this->metadataService->resolveRoleShapeRuleFromSelector($selectorUri, $roleShapeRules);
                if ($resolved) {
                    $resolvedRoleType = $resolved['rolType'] ?? null;
                    $meta = [
                        'rolTbClass' => $resolved['rolTbClass'] ?? null,
                        'vanClass' => $resolved['vanClass'] ?? null,
                        'naarClass' => $resolved['naarClass'] ?? null,
                        'vanProperty' => $resolved['vanProperty'] ?? null,
                        'naarProperty' => $resolved['naarProperty'] ?? null,
                        'label' => null,
                    ];
                }
            }

            if (! $meta) {
                continue;
            }
            $allowedRoles[] = [
                'tb_class' => $selectorUri,
                'label' => $meta['label'] ?? ($resolvedRoleType ? $this->metadataService->shortId($resolvedRoleType) : $this->metadataService->shortId($selectorUri)),
                'van_class' => $meta['vanClass'] ?? null,
                'naar_class' => $meta['naarClass'] ?? null,
                'volgorde' => $row->volgorde ?? 1,
            ];
        }

        return response()->json([
            'transactie_naam' => $ts->naam,
            'sjabloon_uri' => $primarySjabloon['sjabloon_uri'] ?? null,
            'sjabloon_label' => $primarySjabloon['sjabloon_label'] ?? null,
            'target_class' => $primarySjabloon['target_class'] ?? null,
            'velden' => $primarySjabloon['velden'] ?? [],
            'allowed_sjablonen' => $allowed,
            'allowed_roles' => $allowedRoles,
        ]);
    }

    /**
     * Haalt een sjabloon op op basis van een URI (voor dynamische objecten).
     */
    public function getSjabloonByUri(Request $request)
    {
        $validated = $request->validate([
            'uri' => 'required|string',
        ]);

        $sjabloon = $this->metadataService->fetchSjabloon($validated['uri']);

        return response()->json([
            'sjabloon_uri' => $validated['uri'],
            'sjabloon_label' => $sjabloon['sjabloon_label'],
            'target_class' => $sjabloon['target_class'],
            'velden' => $sjabloon['velden'],
        ]);
    }

    /**
     * Lijst alle sjablonen (voor keuzelijst in de UI).
     */
    public function listSjablonen()
    {
        return response()->json([
            'sjablonen' => $this->metadataService->listSjablonen(),
        ]);
    }

    /**
     * Lijst roltypes (voor generieke role-items payload).
     */
    public function listRolTypes()
    {
        return response()->json([
            'roltypes' => $this->metadataService->listRolTypes(),
        ]);
    }

    /**
     * Haalt labels op voor een lijst met URI's (rdfs:label).
     */
    public function listAllLabels()
    {
        return response()->json([
            'labels' => $this->metadataService->listLabels(),
        ]);
    }

    /**
     * Haalt labels op voor een lijst met URI's (rdfs:label).
     */
    public function listLabels(Request $request)
    {
        $uris = $request->input('uris', []);

        return response()->json([
            'labels' => $this->metadataService->listLabels(is_array($uris) ? $uris : []),
        ]);
    }

    /**
     * Lijst identifier-velden per TB-class (op basis van vwm:isIdentifier).
     */
    public function listIdentifiers()
    {
        return response()->json([
            'identifiers' => $this->metadataService->listIdentifiers(),
        ]);
    }

    /**
     * SHACL-validatie uitvoeren op de repository en rapport teruggeven.
     */
    public function validateShacl()
    {
        $result = $this->graphService->validateShacl();

        return response()->json([
            'conforms' => $result['conforms'],
            'report' => $result['report'],
        ]);
    }

}
