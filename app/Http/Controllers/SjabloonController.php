<?php

namespace App\Http\Controllers;

use App\Services\GraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str; // Belangrijk voor UUID's

class SjabloonController extends Controller
{
    protected $graphService;

    public function __construct(GraphService $graphService)
    {
        $this->graphService = $graphService;
    }

    /**
     * Haalt de UI-definitie op uit de Graph op basis van de SQLite ID.
     */
    public function getSjabloonVoorTransactie($transactieSoortId)
    {
        $ts = DB::table('transactie_soorten')->find($transactieSoortId);

        if (!$ts) {
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
            $info = $this->fetchSjabloon($uri);
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
        $roleMeta = $this->fetchRolTbMetaByClasses($roleUris);
        $allowedRoles = [];
        foreach ($linkedRoles as $row) {
            $meta = $roleMeta[$row->sjabloon_uri] ?? null;
            if (!$meta) {
                continue;
            }
            $allowedRoles[] = [
                'tb_class' => $row->sjabloon_uri,
                'label' => $meta['label'] ?? null,
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

        $sjabloon = $this->fetchSjabloon($validated['uri']);

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
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

            SELECT ?sjabloon ?label ?targetClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?sjabloon rdfs:subClassOf vwm:ToestandsBeschrijving .
                    OPTIONAL { ?sjabloon rdfs:label ?label . }
                    OPTIONAL { ?sjabloon vwm:beschrijftClass ?targetClass . }
                }
            }
            ORDER BY ?label
        ";

        $rows = $this->graphService->query($query);

        $items = array_map(function ($row) {
            return [
                'sjabloon_uri' => $row['sjabloon'],
                'label' => $row['label'] ?? null,
                'target_class' => $row['targetClass'] ?? null,
            ];
        }, $rows);

        return response()->json([
            'sjablonen' => $items,
        ]);
    }

    /**
     * Lijst roltypes (voor generieke role-items payload).
     */
    public function listRolTypes()
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

            SELECT ?rolType ?roleKey ?label
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?rolType a vwm:RolType ;
                             vwm:roleKey ?roleKey .
                    OPTIONAL { ?rolType rdfs:label ?label . }
                }
            }
            ORDER BY ?roleKey
        ";

        $rows = $this->graphService->query($query);
        $items = array_map(function ($row) {
            return [
                'role_key' => $row['roleKey'],
                'uri' => $row['rolType'],
                'label' => $row['label'] ?? null,
            ];
        }, $rows);

        return response()->json([
            'roltypes' => $items,
        ]);
    }

    /**
     * Haalt labels op voor een lijst met URI's (rdfs:label).
     */
    public function listLabels(Request $request)
    {
        $uris = $request->input('uris', []);

        $values = is_array($uris)
            ? array_filter($uris, fn ($uri) => is_string($uri) && $uri !== '')
            : [];

        if (!empty($values)) {
            $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", array_unique($values)));
            $query = "
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?uri ?label
                WHERE {
                    GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                        VALUES ?uri { {$iriList} }
                        ?uri rdfs:label ?label .
                    }
                }
            ";
        } else {
            // Fallback: haal alle labels op zodat UI altijd kan tonen.
            $query = "
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?uri ?label
                WHERE {
                    GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                        ?uri rdfs:label ?label .
                    }
                }
            ";
        }

        $rows = $this->graphService->query($query);
        $labels = [];
        foreach ($rows as $row) {
            if (!empty($row['label'])) {
                $labels[$row['uri']] = $row['label'];
            }
        }

        return response()->json([
            'labels' => $labels,
        ]);
    }

    /**
     * Lijst identifier-velden per TB-class (op basis van vwm:isIdentifier).
     */
    public function listIdentifiers()
    {
        $this->assertShapesPresent();

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?tbClass ?describedClass ?property
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?shape sh:targetClass ?tbClass ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
                    ?property vwm:isIdentifier true .
                    ?tbClass vwm:beschrijftClass ?describedClass .
                }
            }
            ORDER BY ?tbClass ?property
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $tbClass = $row['tbClass'];
            if (!isset($map[$tbClass])) {
                $map[$tbClass] = [
                    'tb_class' => $tbClass,
                    'described_class' => $row['describedClass'],
                    'properties' => [],
                ];
            }
            $map[$tbClass]['properties'][] = $row['property'];
        }

        return response()->json([
            'identifiers' => array_values($map),
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

    /**
     * Slaat de formulierdata op in zowel SQLite (audit) als GraphDB (triples).
     */
    public function storeMutatie(Request $request)
    {
        // 1. Basisvalidatie
        $base = $request->validate([
            'transactie_soort_id' => 'required|integer',
            'case_id' => 'required|integer',
        ]);

        $dossier = DB::table('dossiers')
            ->where('case_id', $base['case_id'])
            ->orderBy('id')
            ->first();

        if (!$dossier) {
            return response()->json(['error' => 'Geen dossier gevonden voor deze case'], 422);
        }

        $relatieRegels = $this->fetchRelatieRegels();
        $rolRegels = $this->fetchRolRegels();
        $rolTypesByKey = $this->fetchRolTypesByKey();
        $allowedRolTbClasses = $this->fetchAllowedRolTbClasses($base['transactie_soort_id']);
        $enforceAllowedRolTb = !empty($allowedRolTbClasses);

        // 2. Ondersteun meerdere objecten per scherm (en legacy single-object payload)
        $objects = $request->input('objects');

        if (empty($objects)) {
            $legacy = $request->validate([
                'sjabloon_uri' => 'required|string',
                'target_class' => 'required|string',
                'data' => 'required|array',
            ]);

            $objects = [[
                'client_id' => 'obj_legacy',
                'sjabloon_uri' => $legacy['sjabloon_uri'],
                'target_class' => $legacy['target_class'],
                'data' => $legacy['data'],
            ]];
        } else {
            $request->validate([
                'objects' => 'required|array|min:1',
                'objects.*.client_id' => 'required|string',
                'objects.*.sjabloon_uri' => 'required|string',
                'objects.*.target_class' => 'required|string',
                'objects.*.data' => 'required|array',
                'objects.*.data_types' => 'sometimes|array',
                'roles' => 'sometimes|array',
                'roles.drivers' => 'sometimes|array',
                'roles.owners' => 'sometimes|array',
                'roles.witnesses' => 'sometimes|array',
                'roles.bystanders' => 'sometimes|array',
                'roles.items' => 'sometimes|array',
                'roles.items.*.roleType' => 'sometimes|string',
                'roles.items.*.roleTbClass' => 'sometimes|string',
                'roles.items.*.fromId' => 'sometimes|string',
                'roles.items.*.toId' => 'sometimes|string',
                'roles.items.*.fromGoicId' => 'sometimes|integer',
                'roles.items.*.toGoicId' => 'sometimes|integer',
            ]);
        }

        $objectUris = [];
        $objectMeta = [];
        $allTriples = "";
        $nowIso = now()->toAtomString();
        $vwm = 'http://ontologie.politie.nl/def/vwm#';

        // 3. Registreer de processtap + objectmutaties in SQLite
        $transactieId = DB::transaction(function () use ($base, $objects, &$objectUris, &$allTriples, &$objectMeta, $dossier, $nowIso, $vwm) {
            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => $base['case_id'],
                'transactie_soort_id' => $base['transactie_soort_id'],
                'user_id' => 1, // In een later stadium halen we dit uit Auth::id()
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($objects as $object) {
                $tbClass = $object['sjabloon_uri']; // TB-class (bijv. vwm:PersoonsBeschrijving)
                $describedClass = $object['target_class']; // Domeinclass (bijv. dpm:Person)

                $goUuid = (string) Str::uuid();
                $goicUuid = (string) Str::uuid();
                $tbUuid = (string) Str::uuid();
                $mutatieUuid = (string) Str::uuid();

                $goUri = "http://vwm.voorbeeld.nl/data/go/" . $goUuid;
                $goicUri = "http://vwm.voorbeeld.nl/data/goic/" . $goicUuid;
                $tbUri = "http://vwm.voorbeeld.nl/data/tb/" . $tbUuid;
                $mutatieUri = "http://vwm.voorbeeld.nl/data/mutatie/" . $mutatieUuid;

                $objectUris[] = $tbUri;

                $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
                    'uuid' => $goicUuid,
                    'rdf_uri' => $goicUri,
                    'dossier_id' => $dossier->id,
                    'context_data' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $tbId = DB::table('toestands_beschrijvingen')->insertGetId([
                    'uuid' => $tbUuid,
                    'rdf_uri' => $tbUri,
                    'beschrijving' => $tbClass,
                    'toestand_data' => json_encode($object['data']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $objectMeta[] = [
                    'tb_uri' => $tbUri,
                    'goic_uri' => $goicUri,
                    'goic_id' => $goicId,
                    'tb_id' => $tbId,
                    'target_class' => $describedClass,
                    'client_id' => $object['client_id'] ?? null,
                ];

                DB::table('object_mutaties')->insert([
                    'transactie_id' => $transactieId,
                    'sjabloon_uri' => $tbClass,
                    'object_uri' => $tbUri,
                    'gegevens_object_in_context_id' => $goicId,
                    'geproduceerde_toestand_id' => $tbId,
                    'datum_tijd' => now(),
                    'data' => json_encode($object['data']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // GO + GOIC + TB kernstructuur
                $allTriples .= "<{$goUri}> a <{$vwm}GegevensObject> . \n";
                $allTriples .= "<{$goicUri}> a <{$vwm}GegevensObjectInContext> . \n";
                $allTriples .= "<{$goicUri}> <{$vwm}beschrijftGO> <{$goUri}> . \n";
                $allTriples .= "<{$goicUri}> <{$vwm}hoortBijDossier> <{$dossier->rdf_uri}> . \n";
                $allTriples .= "<{$tbUri}> a <{$tbClass}> . \n";
                $allTriples .= "<{$tbUri}> <{$vwm}beschrijftGOIC> <{$goicUri}> . \n";
                $allTriples .= "<{$tbUri}> <{$vwm}geregistreerdOp> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

                // ObjectMutatie als RDF-event
                $allTriples .= "<{$mutatieUri}> a <{$vwm}ObjectMutatie> . \n";
                $allTriples .= "<{$mutatieUri}> <{$vwm}heeftBetrekkingOp> <{$goicUri}> . \n";
                $allTriples .= "<{$mutatieUri}> <{$vwm}produceert> <{$tbUri}> . \n";
                $allTriples .= "<{$mutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

                $dataTypes = $object['data_types'] ?? [];

                foreach ($object['data'] as $propertyUri => $value) {
                    $valueType = $dataTypes[$propertyUri] ?? 'literal';

                    if (is_array($value)) {
                        foreach ($value as $entry) {
                            if ($entry === null || $entry === '') {
                                continue;
                            }
                            $sparqlValue = $this->toSparqlValue($entry, $valueType);
                            $allTriples .= "<{$tbUri}> <{$propertyUri}> {$sparqlValue} . \n";
                        }
                        continue;
                    }

                    if ($value === null || $value === '') {
                        continue;
                    }

                    $sparqlValue = $this->toSparqlValue($value, $valueType);
                    $allTriples .= "<{$tbUri}> <{$propertyUri}> {$sparqlValue} . \n";
                }
            }

            return $transactieId;
        });

        // 3. Auto-koppelingen op basis van relatie-regels
        $goicByClass = [];
        foreach ($objectMeta as $meta) {
            $goicByClass[$meta['target_class']][] = $meta['goic_uri'];
        }

        // Bestaande GOICs uit dossier(s) ophalen zodat rollen alleen op opgeslagen objecten kunnen worden gezet
        $caseDossierIds = DB::table('dossiers')
            ->where('case_id', $base['case_id'])
            ->pluck('id')
            ->all();

        $existingGoics = [];
        $existingGoicIds = [];
        if (!empty($caseDossierIds)) {
            $existingGoics = DB::table('gegevens_objecten_in_context')
                ->whereIn('dossier_id', $caseDossierIds)
                ->get(['id', 'rdf_uri'])
                ->all();
            $existingGoicIds = array_map(fn ($row) => $row->id, $existingGoics);
        }

        $tbByGoic = [];
        if (!empty($existingGoicIds)) {
            $tbRows = DB::table('object_mutaties')
                ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                ->whereIn('object_mutaties.gegevens_object_in_context_id', $existingGoicIds)
                ->orderBy('object_mutaties.created_at')
                ->get([
                    'object_mutaties.gegevens_object_in_context_id as goic_id',
                    'toestands_beschrijvingen.beschrijving as tb_class',
                ]);

            foreach ($tbRows as $row) {
                if (!empty($row->tb_class)) {
                    $tbByGoic[$row->goic_id] = $row->tb_class;
                }
            }
        }

        $describedByTb = $this->fetchDescribedClassByTbClasses(array_values(array_unique($tbByGoic)));
        $goicMetaById = [];
        foreach ($existingGoics as $goic) {
            $tbClass = $tbByGoic[$goic->id] ?? null;
            $targetClass = $tbClass ? ($describedByTb[$tbClass] ?? null) : null;
            $goicMetaById[$goic->id] = [
                'goic_id' => $goic->id,
                'goic_uri' => $goic->rdf_uri,
                'target_class' => $targetClass,
            ];
            if ($targetClass) {
                $goicByClass[$targetClass][] = $goic->rdf_uri;
            }
        }

        foreach ($relatieRegels as $regel) {
            $froms = $goicByClass[$regel['vanClass']] ?? [];
            $tos = $goicByClass[$regel['naarClass']] ?? [];
            foreach ($froms as $from) {
                foreach ($tos as $to) {
                    $allTriples .= "<{$from}> <{$regel['predicate']}> <{$to}> . \n";
                }
            }
        }

        // 4. Rollen (generiek via rol-regels)
        $roles = $request->input('roles', []);
        $roleItems = $roles['items'] ?? [];
        $roleTbClassesFromItems = array_values(array_filter(array_map(function ($item) {
            return $item['roleTbClass'] ?? null;
        }, $roleItems)));
        $rolTbMetaByClass = $this->fetchRolTbMetaByClasses($roleTbClassesFromItems);

        $clientMap = [];
        foreach ($objectMeta as $meta) {
            if (!empty($meta['client_id'])) {
                $clientMap[$meta['client_id']] = $meta;
            }
        }

        // Legacy keys omzetten naar generieke role-items (via roleKey in RDF)
        $legacyMap = [
            'drivers' => $rolTypesByKey['drivers'] ?? null,
            'owners' => $rolTypesByKey['owners'] ?? null,
            'witnesses' => $rolTypesByKey['witnesses'] ?? null,
            'bystanders' => $rolTypesByKey['bystanders'] ?? null,
        ];
        foreach ($legacyMap as $key => $roleTypeUri) {
            if (!$roleTypeUri || empty($roles[$key]) || !is_array($roles[$key])) {
                continue;
            }
            foreach ($roles[$key] as $role) {
                $roleItems[] = [
                    'roleType' => $roleTypeUri,
                    'fromId' => $role['personId'] ?? $role['fromId'] ?? null,
                    'toId' => $role['vehicleId'] ?? $role['incidentId'] ?? $role['toId'] ?? null,
                ];
            }
        }

        foreach ($roleItems as $roleItem) {
            $roleType = $roleItem['roleType'] ?? null;
            $roleTbClass = $roleItem['roleTbClass'] ?? null;
            $fromId = $roleItem['fromId'] ?? null;
            $toId = $roleItem['toId'] ?? null;
            $fromGoicId = $roleItem['fromGoicId'] ?? null;
            $toGoicId = $roleItem['toGoicId'] ?? null;

            if ((empty($roleType) && empty($roleTbClass)) || (empty($fromId) && empty($fromGoicId))) {
                continue;
            }

            $roleMeta = null;
            if (!empty($roleTbClass)) {
                $roleMeta = $rolTbMetaByClass[$roleTbClass] ?? null;
            }

            if (!$roleMeta && !empty($roleType)) {
                $regel = $rolRegels[$roleType] ?? null;
                if ($regel) {
                    $roleMeta = [
                        'rolTbClass' => $regel['rolTbClass'] ?? null,
                        'vanClass' => $regel['vanClass'] ?? null,
                        'naarClass' => $regel['naarClass'] ?? null,
                        'vanProperty' => $regel['vanProperty'] ?? null,
                        'naarProperty' => $regel['naarProperty'] ?? null,
                    ];
                }
            }

            if (!$roleMeta || empty($roleMeta['rolTbClass'])) {
                continue;
            }

            if ($enforceAllowedRolTb && !in_array($roleMeta['rolTbClass'], $allowedRolTbClasses, true)) {
                continue;
            }

            $fromMeta = null;
            if (!empty($fromGoicId) && !empty($goicMetaById[$fromGoicId])) {
                $fromMeta = $goicMetaById[$fromGoicId];
            } elseif (!empty($fromId) && !empty($clientMap[$fromId])) {
                $fromMeta = $clientMap[$fromId];
            }

            if (!$fromMeta || $fromMeta['target_class'] !== $roleMeta['vanClass']) {
                continue;
            }

            $targetGoics = [];
            if (!empty($toGoicId) && !empty($goicMetaById[$toGoicId])) {
                $toMeta = $goicMetaById[$toGoicId];
                if ($toMeta['target_class'] === $roleMeta['naarClass']) {
                    $targetGoics[] = $toMeta['goic_uri'];
                }
            } elseif (!empty($toId) && !empty($clientMap[$toId])) {
                $toMeta = $clientMap[$toId];
                if ($toMeta['target_class'] === $roleMeta['naarClass']) {
                    $targetGoics[] = $toMeta['goic_uri'];
                }
            } else {
                $targetGoics = $goicByClass[$roleMeta['naarClass']] ?? [];
            }

            foreach ($targetGoics as $toGoic) {
                $roleTbUuid = (string) Str::uuid();
                $roleTbUri = "http://vwm.voorbeeld.nl/data/tb/" . $roleTbUuid;
                $roleMutatieUuid = (string) Str::uuid();
                $roleMutatieUri = "http://vwm.voorbeeld.nl/data/mutatie/" . $roleMutatieUuid;

                $roleData = [
                    'van' => $fromMeta['goic_uri'],
                    'naar' => $toGoic,
                ];
                if (!empty($roleType)) {
                    $roleData['rolType'] = $roleType;
                }

                $roleTbId = DB::table('toestands_beschrijvingen')->insertGetId([
                    'uuid' => $roleTbUuid,
                    'rdf_uri' => $roleTbUri,
                    'beschrijving' => $roleMeta['rolTbClass'],
                    'toestand_data' => json_encode($roleData),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('object_mutaties')->insert([
                    'transactie_id' => $transactieId,
                    'sjabloon_uri' => $roleMeta['rolTbClass'],
                    'object_uri' => $roleTbUri,
                    'gegevens_object_in_context_id' => $fromMeta['goic_id'] ?? null,
                    'geproduceerde_toestand_id' => $roleTbId,
                    'datum_tijd' => now(),
                    'data' => json_encode($roleData),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $allTriples .= "<{$roleTbUri}> a <{$roleMeta['rolTbClass']}> . \n";
                $allTriples .= "<{$roleTbUri}> <{$roleMeta['vanProperty']}> <{$fromMeta['goic_uri']}> . \n";
                $allTriples .= "<{$roleTbUri}> <{$roleMeta['naarProperty']}> <{$toGoic}> . \n";
                if (!empty($roleType)) {
                    $allTriples .= "<{$roleTbUri}> <{$vwm}rolType> <{$roleType}> . \n";
                }
                $allTriples .= "<{$roleTbUri}> <{$vwm}geregistreerdOp> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

                $allTriples .= "<{$roleMutatieUri}> a <{$vwm}ObjectMutatie> . \n";
                $allTriples .= "<{$roleMutatieUri}> <{$vwm}heeftBetrekkingOp> <{$fromMeta['goic_uri']}> . \n";
                $allTriples .= "<{$roleMutatieUri}> <{$vwm}produceert> <{$roleTbUri}> . \n";
                $allTriples .= "<{$roleMutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";
            }
        }

        $sparqlUpdate = "
            INSERT DATA {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    {$allTriples}
                }
            }
        ";

        // 4. Schiet de data naar GraphDB
        try {
            $this->graphService->update($sparqlUpdate);
            // Automatische SHACL-validatie na write
            $validation = $this->graphService->validateShacl();
            if (!$validation['conforms']) {
                // Best-effort rollback van net ingevoegde triples
                $rollback = "
                    DELETE DATA {
                        GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                            {$allTriples}
                        }
                    }
                ";
                $this->graphService->update($rollback);

                $safeReport = $this->sanitizeForJson((string) ($validation['report'] ?? ''));

                return response()->json([
                    'error' => 'SHACL validatie faalde. Mutatie is teruggedraaid.',
                    'report' => $safeReport,
                ], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
        } catch (\Exception $e) {
            $safeMessage = $this->sanitizeForJson($e->getMessage());
            logger()->error('GraphDB update exception', [
                'message' => $safeMessage,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'GraphDB Update mislukt: ' . $safeMessage,
            ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Objecten opgeslagen en gesynchroniseerd met GraphDB',
            'transactie_id' => $transactieId,
            'object_uris' => $objectUris,
        ]);
    }

    private function toSparqlLiteral(mixed $value): string
    {
        $string = is_string($value) ? $value : json_encode($value);
        $escaped = str_replace(
            ["\\", "\"", "\n", "\r", "\t"],
            ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
            $string ?? ''
        );

        return "\"{$escaped}\"";
    }

    private function sanitizeForJson(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = is_string($converted) ? $converted : '';
        }

        // Guard against oversized error payloads in API responses.
        return mb_substr($value, 0, 20000);
    }

    private function toSparqlValue(mixed $value, string $type): string
    {
        if ($type === 'uri' && is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
                return "<{$trimmed}>";
            }
        }

        return $this->toSparqlLiteral($value);
    }

    private function fetchSjabloon(string $sjabloonUri): array
    {
        $this->assertShapesPresent();

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>

            SELECT ?sjabloonLabel ?label ?property ?datatype ?nodeKind ?order ?targetClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    BIND(<{$sjabloonUri}> as ?sjabloon)
                    OPTIONAL { ?sjabloon rdfs:label ?sjabloonLabel . }
                    OPTIONAL { ?sjabloon vwm:beschrijftClass ?targetClass . }
                    ?shape sh:targetClass ?sjabloon ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
                    OPTIONAL { ?propShape sh:datatype ?datatype . }
                    OPTIONAL { ?propShape sh:nodeKind ?nodeKind . }
                    OPTIONAL { ?propShape sh:order ?order . }
                    OPTIONAL { ?property rdfs:label ?label . }
                }
            }
            ORDER BY ?order ?label
        ";

        $rows = $this->graphService->query($query);
        $sjabloonLabel = $rows[0]['sjabloonLabel'] ?? null;
        $targetClass = $rows[0]['targetClass'] ?? null;
        $velden = array_map(function ($row) {
            $property = $row['property'] ?? '';
            $label = $row['label'] ?? $this->shortId($property);
            $datatype = $row['datatype'] ?? null;
            $nodeKind = $row['nodeKind'] ?? null;
            $order = $row['order'] ?? null;
            $type = $this->mapVeldType($property, $datatype, $nodeKind);

            return [
                'label' => $label,
                'property' => $property,
                'type' => $type,
                'volgorde' => $order !== null ? (int) $order : 999,
            ];
        }, $rows);

        usort($velden, function ($a, $b) {
            $order = ($a['volgorde'] ?? 999) <=> ($b['volgorde'] ?? 999);
            if ($order !== 0) return $order;
            return strcmp($a['label'] ?? '', $b['label'] ?? '');
        });

        return [
            'sjabloon_label' => $sjabloonLabel,
            'target_class' => $targetClass,
            'velden' => $velden,
        ];
    }

    private function mapVeldType(string $property, ?string $datatype, ?string $nodeKind): string
    {
        if ($property === 'http://ontologie.politie.nl/def/vwm#heeftBestand') {
            return 'file';
        }

        if ($datatype === 'http://www.w3.org/2001/XMLSchema#date') {
            return 'date';
        }

        if ($datatype === 'http://www.w3.org/2001/XMLSchema#dateTime') {
            return 'datetime-local';
        }

        if (in_array($datatype, [
            'http://www.w3.org/2001/XMLSchema#integer',
            'http://www.w3.org/2001/XMLSchema#decimal',
        ], true)) {
            return 'number';
        }

        if ($nodeKind === 'http://www.w3.org/ns/shacl#IRI') {
            return 'url';
        }

        return 'text';
    }

    private function assertShapesPresent(): void
    {
        $query = "
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT (COUNT(?shape) AS ?shapeCount)
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?shape a sh:NodeShape .
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $count = (int) ($rows[0]['shapeCount'] ?? 0);
        if ($count === 0) {
            throw new \RuntimeException('Geen SHACL shapes gevonden in GraphDB. Laad Docs/shapes.ttl in de ontologie-graph.');
        }
    }

    private function shortId(string $uri): string
    {
        if ($uri === '') return '';
        $trimmed = rtrim($uri, '/');
        if (str_contains($trimmed, '#')) {
            $parts = explode('#', $trimmed);
            return $parts[count($parts) - 1] ?? $uri;
        }
        $parts = explode('/', $trimmed);
        return $parts[count($parts) - 1] ?? $uri;
    }

    private function fetchRelatieRegels(): array
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?vanClass ?naarClass ?predicate
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?regel a vwm:RelatieRegel ;
                           vwm:vanClass ?vanClass ;
                           vwm:naarClass ?naarClass ;
                           vwm:predicate ?predicate .
                }
            }
        ";

        return $this->graphService->query($query);
    }

    private function fetchRolRegels(): array
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?rolType ?rolTbClass ?vanClass ?naarClass ?vanProperty ?naarProperty
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?regel a vwm:RolRegel ;
                           vwm:rolType ?rolType ;
                           vwm:rolTbClass ?rolTbClass ;
                           vwm:vanClass ?vanClass ;
                           vwm:naarClass ?naarClass ;
                           vwm:vanProperty ?vanProperty ;
                           vwm:naarProperty ?naarProperty .
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $regels = [];
        foreach ($rows as $row) {
            $regels[$row['rolType']] = $row;
        }
        return $regels;
    }

    private function fetchRolTbMetaByClasses(array $tbClasses): array
    {
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            SELECT ?tbClass ?vanClass ?naarClass ?vanProperty ?naarProperty ?label
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    VALUES ?tbClass { {$iriList} }
                    ?tbClass vwm:vanClass ?vanClass ;
                             vwm:naarClass ?naarClass ;
                             vwm:vanProperty ?vanProperty ;
                             vwm:naarProperty ?naarProperty .
                    OPTIONAL { ?tbClass rdfs:label ?label . }
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['tbClass']] = [
                'rolTbClass' => $row['tbClass'],
                'vanClass' => $row['vanClass'],
                'naarClass' => $row['naarClass'],
                'vanProperty' => $row['vanProperty'],
                'naarProperty' => $row['naarProperty'],
                'label' => $row['label'] ?? null,
            ];
        }
        return $map;
    }

    private function fetchDescribedClassByTbClasses(array $tbClasses): array
    {
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass ?describedClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    VALUES ?tbClass { {$iriList} }
                    ?tbClass vwm:beschrijftClass ?describedClass .
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['tbClass']] = $row['describedClass'];
        }
        return $map;
    }

    private function fetchRolTypesByKey(): array
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?roleKey ?rolType
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?rolType a vwm:RolType ;
                             vwm:roleKey ?roleKey .
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['roleKey']] = $row['rolType'];
        }
        return $map;
    }

    private function fetchAllowedRolTbClasses(int $transactieSoortId): array
    {
        return DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'rol')
            ->orderBy('volgorde')
            ->pluck('sjabloon_uri')
            ->all();
    }
}
