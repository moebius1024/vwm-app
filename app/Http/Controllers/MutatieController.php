<?php

namespace App\Http\Controllers;

use App\Services\GraphService;
use App\Services\SjabloonMetadataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MutatieController extends Controller
{
    protected $graphService;

    protected $metadataService;

    public function __construct(GraphService $graphService, SjabloonMetadataService $metadataService)
    {
        $this->graphService = $graphService;
        $this->metadataService = $metadataService;
    }

    /**
     * Slaat de formulierdata op in zowel SQLite (audit) als GraphDB (triples).
     */
    public function storeMutatie(Request $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        // 1. Basisvalidatie
        $base = $request->validate([
            'transactie_soort_id' => 'required|integer',
            'case_id' => 'required|integer',
        ]);

        $case = DB::table('cases')
            ->where('id', $base['case_id'])
            ->where('user_id', $userId)
            ->first(['id']);

        if (! $case) {
            return response()->json(['error' => 'Geen toegang tot deze case.'], 403);
        }

        $dossier = DB::table('dossiers')
            ->where('case_id', $base['case_id'])
            ->orderBy('id')
            ->first();

        if (! $dossier) {
            return response()->json(['error' => 'Geen dossier gevonden voor deze case'], 422);
        }

        $relatieRegels = $this->metadataService->fetchRelatieRegels();
        $roleShapeRules = $this->metadataService->fetchRoleShapeRules();
        $rolTypesByKey = $this->metadataService->fetchRolTypesByKey();
        $allowedRoleSelectors = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $base['transactie_soort_id'])
            ->where('type', 'rol')
            ->orderBy('volgorde')
            ->pluck('sjabloon_uri')
            ->all();
        $enforceAllowedRole = ! empty($allowedRoleSelectors);

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
                'roles.items' => 'sometimes|array',
                'roles.items.*.roleType' => 'sometimes|string',
                'roles.items.*.roleTbClass' => 'sometimes|string',
                'roles.items.*.fromId' => 'sometimes|string',
                'roles.items.*.toId' => 'sometimes|string',
                'roles.items.*.fromGoicId' => 'sometimes|integer',
                'roles.items.*.toGoicId' => 'sometimes|integer',
            ]);
        }

        $tbClasses = array_values(array_filter(array_unique(array_map(function ($object) {
            return $object['sjabloon_uri'] ?? null;
        }, $objects))));
        $describedClassByTbClass = $this->metadataService->fetchDescribedClassByTbClasses($tbClasses);

        foreach ($objects as &$object) {
            $tbClass = $object['sjabloon_uri'] ?? null;
            $expectedTargetClass = is_string($tbClass) ? ($describedClassByTbClass[$tbClass] ?? null) : null;

            if (! is_string($expectedTargetClass) || $expectedTargetClass === '') {
                return response()->json([
                    'error' => "Onbekende of onvolledige sjabloondefinitie: {$tbClass}",
                ], 422);
            }

            if (($object['target_class'] ?? null) !== $expectedTargetClass) {
                return response()->json([
                    'error' => "target_class komt niet overeen met sjabloon {$tbClass}.",
                ], 422);
            }

            $object['target_class'] = $expectedTargetClass;
        }
        unset($object);

        $valueHintsByTbClass = $this->metadataService->fetchPropertyValueHintsByTbClasses($tbClasses);

        $targetClassLimit = $this->fetchTargetClassLimitForTransactie($base['transactie_soort_id']);
        if ($targetClassLimit) {
            $targetClassUri = $targetClassLimit['target_class_uri'];
            $maxAllowed = $targetClassLimit['max_count'];
            $newTargetClassCount = count(array_filter($objects, function ($object) use ($targetClassUri) {
                return ($object['target_class'] ?? null) === $targetClassUri;
            }));

            if ($newTargetClassCount > 0) {
                $existingTargetClassCount = $this->countGoicsForCaseByDescribedClass($base['case_id'], $targetClassUri);
                if (($existingTargetClassCount + $newTargetClassCount) > $maxAllowed) {
                    return response()->json([
                        'error' => "Maximaal {$maxAllowed} object(en) toegestaan voor class {$targetClassUri} in dit dossier.",
                    ], 422);
                }
            }
        }

        $objectUris = [];
        $objectMeta = [];
        $allTriples = '';
        $nowIso = now()->toAtomString();
        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $graphUpdated = false;
        $identityRulesByTbClass = $this->metadataService->fetchIdentityRulesByTbClasses($tbClasses);
        $identityEntries = $this->collectIdentityEntriesForObjects($objects, $identityRulesByTbClass);
        $existingGoByIdentityKey = $this->fetchExistingGoByIdentityEntries($identityEntries);

        DB::beginTransaction();

        // 3. Registreer de processtap + objectmutaties in SQLite
        try {
            $transactieId = DB::transaction(function () use ($base, $objects, &$objectUris, &$allTriples, &$objectMeta, $dossier, $nowIso, $vwm, $valueHintsByTbClass, $identityRulesByTbClass, $existingGoByIdentityKey, $userId) {
                $transactieId = DB::table('transacties')->insertGetId([
                    'case_id' => $base['case_id'],
                    'transactie_soort_id' => $base['transactie_soort_id'],
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $goByIdentityKey = $existingGoByIdentityKey;

                foreach ($objects as $object) {
                    $tbClass = $object['sjabloon_uri']; // TB-class (bijv. vwm:PersoonsBeschrijving)
                    $describedClass = $object['target_class']; // Domeinclass (bijv. dpm:Person)

                    $identityEntry = $this->resolveObjectIdentityEntry($object, $identityRulesByTbClass);
                    $identityKey = is_array($identityEntry) ? ($identityEntry['key'] ?? null) : null;
                    $goUri = is_string($identityKey)
                        ? ($goByIdentityKey[$identityKey] ?? null)
                        : null;

                    if (! is_string($goUri) || $goUri === '') {
                        $goUri = 'http://vwm.voorbeeld.nl/data/go/'.((string) Str::uuid());
                    }

                    if (is_string($identityKey) && $identityKey !== '') {
                        $goByIdentityKey[$identityKey] = $goUri;
                    }

                    $goicUuid = (string) Str::uuid();
                    $tbUuid = (string) Str::uuid();
                    $mutatieUuid = (string) Str::uuid();

                    $goicUri = 'http://vwm.voorbeeld.nl/data/goic/'.$goicUuid;
                    $tbUri = 'http://vwm.voorbeeld.nl/data/tb/'.$tbUuid;
                    $mutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.$mutatieUuid;

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
                        'toestand_data' => null,
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
                    $valueHints = $valueHintsByTbClass[$tbClass] ?? [];

                    foreach ($object['data'] as $propertyUri => $value) {
                        $valueType = $this->resolveValueType(
                            $dataTypes[$propertyUri] ?? null,
                            $valueHints[$propertyUri] ?? null
                        );

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
            if (! empty($caseDossierIds)) {
                $existingGoics = DB::table('gegevens_objecten_in_context')
                    ->whereIn('dossier_id', $caseDossierIds)
                    ->get(['id', 'rdf_uri'])
                    ->all();
                $existingGoicIds = array_map(fn ($row) => $row->id, $existingGoics);
            }

            $tbHistoryByGoic = [];
            $tbClassesInUse = [];
            if (! empty($existingGoicIds)) {
                $tbRows = DB::table('object_mutaties')
                    ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                    ->whereIn('object_mutaties.gegevens_object_in_context_id', $existingGoicIds)
                    ->orderBy('object_mutaties.created_at')
                    ->orderBy('object_mutaties.id')
                    ->get([
                        'object_mutaties.gegevens_object_in_context_id as goic_id',
                        'toestands_beschrijvingen.beschrijving as tb_class',
                    ]);

                foreach ($tbRows as $row) {
                    if (! empty($row->tb_class)) {
                        $tbHistoryByGoic[$row->goic_id][] = $row->tb_class;
                        $tbClassesInUse[$row->tb_class] = true;
                    }
                }
            }

            $describedByTb = [];
            if (! empty($tbClassesInUse)) {
                $describedByTb = $this->metadataService->fetchDescribedClassByTbClasses(array_keys($tbClassesInUse));
            }

            $goicMetaById = [];
            foreach ($existingGoics as $goic) {
                $tbHistory = $tbHistoryByGoic[$goic->id] ?? [];
                $targetClass = null;

                // Neem de meest recente TB die echt een domeinclass beschrijft;
                // rol-mutaties mogen de class van het bronobject niet "overschrijven".
                for ($index = count($tbHistory) - 1; $index >= 0; $index--) {
                    $tbClass = $tbHistory[$index];
                    $candidateTargetClass = $describedByTb[$tbClass] ?? null;
                    if (is_string($candidateTargetClass) && $candidateTargetClass !== '') {
                        $targetClass = $candidateTargetClass;
                        break;
                    }
                }

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
            $roleItems = is_array($roles['items'] ?? null) ? $roles['items'] : [];
            $roleTbClassesFromItems = array_values(array_filter(array_map(function ($item) {
                return $item['roleTbClass'] ?? null;
            }, $roleItems)));
            $rolTbMetaByClass = $this->metadataService->fetchRolTbMetaByClasses($roleTbClassesFromItems);

            $clientMap = [];
            foreach ($objectMeta as $meta) {
                if (! empty($meta['client_id'])) {
                    $clientMap[$meta['client_id']] = $meta;
                }
            }

            // Legacy payloads: elke key onder roles (behalve items) is een roleKey uit RDF.
            foreach ($roles as $roleKey => $legacyRoles) {
                if ($roleKey === 'items' || ! is_array($legacyRoles)) {
                    continue;
                }

                $roleTypeUri = $rolTypesByKey[$roleKey] ?? null;
                if (! $roleTypeUri) {
                    continue;
                }

                foreach ($legacyRoles as $role) {
                    if (! is_array($role)) {
                        continue;
                    }

                    [$fromId, $toId] = $this->extractLegacyRoleEndpoints($role);
                    $roleItems[] = [
                        'roleType' => $roleTypeUri,
                        'fromId' => $fromId,
                        'toId' => $toId,
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
                if (! empty($roleTbClass)) {
                    $roleMeta = $rolTbMetaByClass[$roleTbClass] ?? null;
                }

                if (! $roleMeta && ! empty($roleType)) {
                    $regel = $roleShapeRules[$roleType] ?? null;
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

                if (! $roleMeta && ! empty($roleTbClass)) {
                    $regel = $this->metadataService->resolveRoleShapeRuleFromSelector($roleTbClass, $roleShapeRules);
                    if ($regel) {
                        if (empty($roleType) && ! empty($regel['rolType'])) {
                            $roleType = $regel['rolType'];
                        }
                        $roleMeta = [
                            'rolTbClass' => $regel['rolTbClass'] ?? null,
                            'vanClass' => $regel['vanClass'] ?? null,
                            'naarClass' => $regel['naarClass'] ?? null,
                            'vanProperty' => $regel['vanProperty'] ?? null,
                            'naarProperty' => $regel['naarProperty'] ?? null,
                        ];
                    }
                }

                if (! $roleMeta || empty($roleMeta['rolTbClass'])) {
                    continue;
                }

                if ($enforceAllowedRole && ! $this->isAllowedRoleSelection($roleType, $roleTbClass, $allowedRoleSelectors, $roleShapeRules)) {
                    continue;
                }

                $fromMeta = null;
                if (! empty($fromGoicId) && ! empty($goicMetaById[$fromGoicId])) {
                    $fromMeta = $goicMetaById[$fromGoicId];
                } elseif (! empty($fromId) && ! empty($clientMap[$fromId])) {
                    $fromMeta = $clientMap[$fromId];
                }

                if (! $fromMeta || $fromMeta['target_class'] !== $roleMeta['vanClass']) {
                    continue;
                }

                $targetGoics = [];
                if (! empty($toGoicId) && ! empty($goicMetaById[$toGoicId])) {
                    $toMeta = $goicMetaById[$toGoicId];
                    if ($toMeta['target_class'] === $roleMeta['naarClass']) {
                        $targetGoics[] = $toMeta['goic_uri'];
                    }
                } elseif (! empty($toId) && ! empty($clientMap[$toId])) {
                    $toMeta = $clientMap[$toId];
                    if ($toMeta['target_class'] === $roleMeta['naarClass']) {
                        $targetGoics[] = $toMeta['goic_uri'];
                    }
                } else {
                    $targetGoics = $goicByClass[$roleMeta['naarClass']] ?? [];
                }

                foreach ($targetGoics as $toGoic) {
                    $roleTbUuid = (string) Str::uuid();
                    $roleTbUri = 'http://vwm.voorbeeld.nl/data/tb/'.$roleTbUuid;
                    $roleMutatieUuid = (string) Str::uuid();
                    $roleMutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.$roleMutatieUuid;

                    $roleData = [
                        'van' => $fromMeta['goic_uri'],
                        'naar' => $toGoic,
                    ];
                    if (! empty($roleType)) {
                        $roleData['rolType'] = $roleType;
                    }

                    $roleTbId = DB::table('toestands_beschrijvingen')->insertGetId([
                        'uuid' => $roleTbUuid,
                        'rdf_uri' => $roleTbUri,
                        'beschrijving' => $roleMeta['rolTbClass'],
                        'toestand_data' => null,
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
                    if (! empty($roleType)) {
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
            $this->graphService->update($sparqlUpdate);
            $graphUpdated = true;

            // Automatische SHACL-validatie na write
            $validation = $this->graphService->validateShacl();
            if (! $validation['conforms']) {
                $this->rollbackGraphTriples($allTriples);
                DB::rollBack();

                $safeReport = $this->sanitizeForJson((string) ($validation['report'] ?? ''));

                return response()->json([
                    'error' => 'SHACL validatie faalde. Mutatie is teruggedraaid.',
                    'report' => $safeReport,
                ], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            DB::commit();
        } catch (\Throwable $e) {
            if ($graphUpdated) {
                $this->rollbackGraphTriples($allTriples);
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $safeMessage = $this->sanitizeForJson($e->getMessage());
            logger()->error('GraphDB update exception', [
                'message' => $safeMessage,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'GraphDB Update mislukt: '.$safeMessage,
            ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Objecten opgeslagen en gesynchroniseerd met GraphDB',
            'transactie_id' => $transactieId,
            'object_uris' => $objectUris,
        ]);
    }

    /**
     * Volg een bestaand GOIC vanuit een andere case:
     * 1) maak GOIC aan met dezelfde toestand-class als bron
     * 2) koppel aan dezelfde GO
     * 3) leg DataObjectAssociation vast
     * 4) leg stap 1 en 3 ook vast als object_mutaties in SQLite
     */
    public function volgGoic(Request $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        $validated = $request->validate([
            'case_id' => 'required|integer',
            'bron_goic_uri' => 'required|string',
        ]);

        // Hard guard: dit endpoint accepteert exact 1 bron-GOIC per request.
        if ($request->has('bron_goic_uris')) {
            logger()->warning('volgGoic 422: bron_goic_uris aanwezig', [
                'case_id' => $validated['case_id'] ?? null,
                'user_id' => $userId,
            ]);
            return response()->json([
                'error' => 'Gebruik exact één bron_goic_uri per request.',
                'reason' => 'multiple_input_field',
            ], 422);
        }

        if (is_array($request->input('bron_goic_uri'))) {
            logger()->warning('volgGoic 422: bron_goic_uri is array', [
                'case_id' => $validated['case_id'] ?? null,
                'user_id' => $userId,
            ]);
            return response()->json([
                'error' => 'bron_goic_uri mag geen lijst zijn.',
                'reason' => 'bron_goic_uri_array',
            ], 422);
        }

        $targetCase = DB::table('cases')
            ->where('id', (int) $validated['case_id'])
            ->where('user_id', $userId)
            ->first(['id', 'case_soort_id']);

        if (! $targetCase) {
            return response()->json(['error' => 'Geen toegang tot deze case.'], 403);
        }

        $targetDossier = DB::table('dossiers')
            ->where('case_id', (int) $targetCase->id)
            ->orderBy('id')
            ->first(['id', 'rdf_uri']);

        if (! $targetDossier) {
            logger()->warning('volgGoic 422: geen dossier', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
            ]);
            return response()->json([
                'error' => 'Geen dossier gevonden voor deze case.',
                'reason' => 'target_case_has_no_dossier',
            ], 422);
        }

        $bronGoicUri = trim((string) $validated['bron_goic_uri']);
        if ($bronGoicUri === '' || preg_match('/[\s,;]/', $bronGoicUri)) {
            logger()->warning('volgGoic 422: ongeldige single bron_goic_uri syntax', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $validated['bron_goic_uri'] ?? null,
            ]);
            return response()->json([
                'error' => 'Gebruik exact één geldige bron_goic_uri.',
                'reason' => 'invalid_single_uri_syntax',
            ], 422);
        }

        if (! preg_match('/^https?:\/\/[^\s<>"\']+$/', $bronGoicUri)) {
            logger()->warning('volgGoic 422: bron_goic_uri regex mismatch', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);
            return response()->json([
                'error' => 'Ongeldige bron GOIC URI.',
                'reason' => 'invalid_uri_format',
            ], 422);
        }

        $sourceMeta = $this->fetchSourceGoicMeta($bronGoicUri);
        if (! $sourceMeta) {
            logger()->warning('volgGoic 422: source meta niet gevonden', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);
            return response()->json([
                'error' => 'Bron GOIC niet gevonden in GraphDB.',
                'reason' => 'source_meta_missing',
            ], 422);
        }

        $goUri = $sourceMeta['go_uri'] ?? null;
        if (! is_string($goUri) || $goUri === '') {
            logger()->warning('volgGoic 422: bron GO ontbreekt', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);
            return response()->json([
                'error' => 'Kon geen GO vinden voor bron GOIC.',
                'reason' => 'source_go_missing',
            ], 422);
        }

        $alreadyFollowed = $this->findExistingFollowedGoicForCase((int) $targetCase->id, $bronGoicUri);
        if ($alreadyFollowed) {
            return response()->json([
                'message' => 'Deze case volgt deze GOIC al.',
                'goic_id' => (int) $alreadyFollowed['goic_id'],
                'goic_uri' => $alreadyFollowed['goic_uri'],
                'already_exists' => true,
            ], 200);
        }

        $transactieSoortId = DB::table('case_soort_transactie')
            ->where('case_soort_id', (int) $targetCase->case_soort_id)
            ->orderBy('volgorde')
            ->value('transactie_soort_id');

        if (! $transactieSoortId) {
            $transactieSoortId = DB::table('transactie_soorten')->orderBy('id')->value('id');
        }

        if (! $transactieSoortId) {
            logger()->warning('volgGoic 422: geen transactie soort', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
            ]);
            return response()->json([
                'error' => 'Geen transactie-soort beschikbaar.',
                'reason' => 'transactie_soort_missing',
            ], 422);
        }

        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $dpm = 'http://ontologie.politie.nl/def/dpm#';
        $now = now();
        $nowIso = $now->toAtomString();
        $result = DB::transaction(function () use ($targetCase, $targetDossier, $transactieSoortId, $bronGoicUri, $goUri, $vwm, $dpm, $now, $nowIso, $userId) {
            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => (int) $targetCase->id,
                'transactie_soort_id' => (int) $transactieSoortId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $newGoicUuid = (string) Str::uuid();
            $newGoicUri = "http://vwm.voorbeeld.nl/data/goic/{$newGoicUuid}";
            $associationUri = 'http://vwm.voorbeeld.nl/data/association/'.((string) Str::uuid());

            $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
                'uuid' => $newGoicUuid,
                'rdf_uri' => $newGoicUri,
                'dossier_id' => (int) $targetDossier->id,
                'context_data' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Stap 1: nieuwe GOIC mutatie in SQLite.
            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => "{$vwm}GegevensObjectInContext",
                'object_uri' => $newGoicUri,
                'gegevens_object_in_context_id' => $goicId,
                'geproduceerde_toestand_id' => null,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'actie' => 'volg_goic',
                    'bronGoic' => $bronGoicUri,
                    'goicUri' => $newGoicUri,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Stap 3: DataObjectAssociation mutatie in SQLite.
            $associationMutatieId = DB::table('object_mutaties')->insertGetId([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => "{$dpm}DataObjectAssociation",
                'object_uri' => $associationUri,
                'gegevens_object_in_context_id' => $goicId,
                'geproduceerde_toestand_id' => null,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'ownedObject' => $newGoicUri,
                    'targetObject' => $bronGoicUri,
                    'producedAtTime' => $nowIso,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('data_object_associations')->insert([
                'uuid' => (string) Str::uuid(),
                'rdf_uri' => $associationUri,
                'object_mutatie_id' => $associationMutatieId,
                'owned_goic_uri' => $newGoicUri,
                'target_goic_uri' => $bronGoicUri,
                'produced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $mutatie1Uri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
            $mutatie2Uri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
            $triples = '';
            $triples .= "<{$newGoicUri}> a <{$vwm}GegevensObjectInContext> .\n";
            $triples .= "<{$newGoicUri}> <{$vwm}beschrijftGO> <{$goUri}> .\n";
            $triples .= "<{$newGoicUri}> <{$vwm}hoortBijDossier> <{$targetDossier->rdf_uri}> .\n";

            $triples .= "<{$associationUri}> a <{$dpm}DataObjectAssociation> .\n";
            $triples .= "<{$associationUri}> <{$dpm}ownedObject> <{$newGoicUri}> .\n";
            $triples .= "<{$associationUri}> <{$dpm}targetObject> <{$bronGoicUri}> .\n";
            $triples .= "<{$associationUri}> <{$dpm}producedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $triples .= "<{$mutatie1Uri}> a <{$vwm}ObjectMutatie> .\n";
            $triples .= "<{$mutatie1Uri}> <{$vwm}heeftBetrekkingOp> <{$newGoicUri}> .\n";
            $triples .= "<{$mutatie1Uri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $triples .= "<{$mutatie2Uri}> a <{$vwm}ObjectMutatie> .\n";
            $triples .= "<{$mutatie2Uri}> <{$vwm}heeftBetrekkingOp> <{$newGoicUri}> .\n";
            $triples .= "<{$mutatie2Uri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $this->graphService->update("
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        {$triples}
                    }
                }
            ");

            return [
                'goic_id' => $goicId,
                'goic_uri' => $newGoicUri,
                'association_uri' => $associationUri,
            ];
        });

        return response()->json([
            'message' => 'GOIC wordt nu gevolgd vanuit deze case.',
            'goic_id' => $result['goic_id'],
            'goic_uri' => $result['goic_uri'],
            'association_uri' => $result['association_uri'],
        ]);
    }

    /**
     * Resolveer leesbare labels voor GOIC-URI's (ook buiten de actieve case),
     * zodat verwijzingen zoals heeftVoertuig het kenteken kunnen tonen.
     */
    public function resolveGoicDisplays(Request $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        $validated = $request->validate([
            'uris' => 'required|array|min:1',
            'uris.*' => 'required|string',
        ]);

        $uris = array_values(array_unique(array_filter($validated['uris'], function ($uri) {
            return is_string($uri) && str_contains($uri, '/data/goic/');
        })));

        if (empty($uris)) {
            return response()->json(['labels' => []]);
        }

        $goics = DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->where('cases.user_id', $userId)
            ->whereIn('gegevens_objecten_in_context.rdf_uri', $uris)
            ->get([
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
            ]);

        $goicByUri = [];
        foreach ($goics as $row) {
            $goicByUri[$row->goic_uri] = (int) $row->goic_id;
        }

        $labels = [];
        foreach ($uris as $uri) {
            $goicId = $goicByUri[$uri] ?? null;
            if (! is_int($goicId) || $goicId <= 0) {
                $labels[$uri] = $this->resolveGoicLabelFromGraph($uri) ?? "GOIC {$this->shortId($uri)}";
                continue;
            }

            $label = "GOIC {$this->shortId($uri)}";
            $rows = DB::table('object_mutaties')
                ->where('gegevens_object_in_context_id', $goicId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['sjabloon_uri', 'data']);

            foreach ($rows as $row) {
                $data = json_decode((string) ($row->data ?? '{}'), true);
                if (! is_array($data)) {
                    continue;
                }

                $kenteken = $this->extractValueBySuffix($data, '#licensePlate')
                    ?? $this->extractValueBySuffix($data, 'licensePlate')
                    ?? $this->extractValueBySuffix($data, '#kenteken')
                    ?? $this->extractValueBySuffix($data, 'kenteken');

                if (is_string($kenteken) && trim($kenteken) !== '') {
                    $labels[$uri] = 'Voertuig: '.trim($kenteken);
                    continue 2;
                }
            }

            $labels[$uri] = $label;
        }

        return response()->json(['labels' => $labels]);
    }

    private function fetchSourceGoicMeta(string $goicUri): ?array
    {
        $goic = DB::table('gegevens_objecten_in_context')
            ->where('rdf_uri', $goicUri)
            ->first(['id']);
        if (! $goic) {
            return null;
        }

        $latest = DB::table('object_mutaties')
            ->join('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->where('object_mutaties.gegevens_object_in_context_id', (int) $goic->id)
            ->orderByDesc('object_mutaties.created_at')
            ->orderByDesc('object_mutaties.id')
            ->first([
                'toestands_beschrijvingen.beschrijving as tb_class',
                'toestands_beschrijvingen.rdf_uri as tb_uri',
                'object_mutaties.data as data',
            ]);
        if (! $latest || ! is_string($latest->tb_class) || $latest->tb_class === '') {
            return null;
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?go
            WHERE {
                <{$goicUri}> vwm:beschrijftGO ?go .
            }
            LIMIT 1
        ";
        $rows = $this->graphService->query($query);
        if (empty($rows[0]['go'])) {
            return null;
        }

        $stateData = json_decode((string) ($latest->data ?? '{}'), true);
        if (! is_array($stateData)) {
            $stateData = [];
        }

        return [
            'go_uri' => $rows[0]['go'] ?? null,
            'source_tb_class' => $latest->tb_class,
            'source_tb_uri' => $latest->tb_uri,
            'source_state_data' => $stateData,
        ];
    }

    private function findExistingFollowedGoicForCase(int $caseId, string $bronGoicUri): ?array
    {
        $dossierIds = DB::table('dossiers')
            ->where('case_id', $caseId)
            ->pluck('id')
            ->all();

        if (empty($dossierIds)) {
            return null;
        }

        $caseGoicUris = DB::table('gegevens_objecten_in_context')
            ->whereIn('dossier_id', $dossierIds)
            ->pluck('rdf_uri')
            ->all();

        if (empty($caseGoicUris)) {
            return null;
        }

        $values = implode(' ', array_map(fn ($uri) => "<{$uri}>", $caseGoicUris));
        $query = "
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT ?owned
            WHERE {
                ?assoc a dpm:DataObjectAssociation ;
                       dpm:ownedObject ?owned ;
                       dpm:targetObject <{$bronGoicUri}> .
                VALUES ?owned { {$values} }
            }
            LIMIT 1
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable) {
            return null;
        }

        $ownedUri = $rows[0]['owned'] ?? null;
        if (! is_string($ownedUri) || $ownedUri === '') {
            return null;
        }

        $goic = DB::table('gegevens_objecten_in_context')
            ->where('rdf_uri', $ownedUri)
            ->first(['id', 'rdf_uri']);

        if (! $goic) {
            return null;
        }

        return [
            'goic_id' => (int) $goic->id,
            'goic_uri' => (string) $goic->rdf_uri,
        ];
    }

    private function resolveAssociationTbClass(): ?string
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT ?tbClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?tbClass vwm:beschrijftClass dpm:DataObjectAssociation .
                }
            }
            LIMIT 1
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable) {
            return null;
        }

        $tbClass = $rows[0]['tbClass'] ?? null;

        return is_string($tbClass) && $tbClass !== '' ? $tbClass : null;
    }

    private function inferValueTypeFromSourceData(array $sourceTbDataByUri, string $tbClass, string $property, mixed $value): ?string
    {
        $hints = $this->metadataService->fetchPropertyValueHintsByTbClasses([$tbClass]);
        $hint = $hints[$tbClass][$property] ?? null;
        if (is_string($hint) && $hint !== '') {
            return $hint;
        }

        if (is_array($value)) {
            $first = null;
            foreach ($value as $entry) {
                if ($entry !== null && $entry !== '') {
                    $first = $entry;
                    break;
                }
            }
            $value = $first;
        }

        if (is_string($value)) {
            if (preg_match('/^-?\d+$/', trim($value))) {
                return 'integer';
            }
            if (preg_match('/^-?\d+(?:\.\d+)?$/', trim($value))) {
                return 'decimal';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
                return 'date';
            }
            if ($this->normalizeDateTimeLexical($value) !== null) {
                return 'dateTime';
            }
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return 'uri';
            }
        }

        return null;
    }

    private function toSparqlLiteral(mixed $value): string
    {
        $string = is_string($value) ? $value : json_encode($value);
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $string ?? ''
        );

        return "\"{$escaped}\"";
    }

    private function sanitizeForJson(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = is_string($converted) ? $converted : '';
        }

        // Guard against oversized error payloads in API responses.
        return mb_substr($value, 0, 20000);
    }

    private function extractValueBySuffix(array $data, string $suffix): ?string
    {
        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, $suffix)) {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function shortId(string $uri): string
    {
        $trimmed = str_ends_with($uri, '/') ? substr($uri, 0, -1) : $uri;
        if (str_contains($trimmed, '#')) {
            $parts = explode('#', $trimmed);

            return (string) end($parts);
        }

        $parts = explode('/', $trimmed);

        return (string) end($parts);
    }

    private function resolveGoicLabelFromGraph(string $goicUri): ?string
    {
        if (! str_contains($goicUri, '/data/goic/')) {
            return null;
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT ?plate ?brand ?model
            WHERE {
                ?tb vwm:beschrijftGOIC <{$goicUri}> .
                OPTIONAL { ?tb dpm:licensePlate ?plate . }
                OPTIONAL { ?tb dpm:brand ?brand . }
                OPTIONAL { ?tb dpm:model ?model . }
                OPTIONAL { ?tb vwm:geregistreerdOp ?at . }
            }
            ORDER BY DESC(?at)
            LIMIT 1
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable) {
            return null;
        }

        $plate = $rows[0]['plate'] ?? null;
        if (is_string($plate) && trim($plate) !== '') {
            return 'Voertuig: '.trim($plate);
        }

        $brand = is_string($rows[0]['brand'] ?? null) ? trim((string) $rows[0]['brand']) : '';
        $model = is_string($rows[0]['model'] ?? null) ? trim((string) $rows[0]['model']) : '';
        if ($brand !== '' || $model !== '') {
            return 'Voertuig: '.trim("{$brand} {$model}");
        }

        return null;
    }

    private function rollbackGraphTriples(string $triples): void
    {
        if (trim($triples) === '') {
            return;
        }

        $rollback = "
            DELETE DATA {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    {$triples}
                }
            }
        ";

        try {
            $this->graphService->update($rollback);
        } catch (\Throwable $e) {
            logger()->warning('GraphDB rollback exception', [
                'message' => $this->sanitizeForJson($e->getMessage()),
            ]);
        }
    }

    private function isAllowedRoleSelection(?string $roleType, ?string $roleSelector, array $allowedSelectors, array $roleShapeRules): bool
    {
        foreach ($allowedSelectors as $allowedSelector) {
            if (! is_string($allowedSelector) || $allowedSelector === '') {
                continue;
            }

            if ($roleSelector === $allowedSelector || $roleType === $allowedSelector) {
                return true;
            }

            $allowedRule = $this->metadataService->resolveRoleShapeRuleFromSelector($allowedSelector, $roleShapeRules);
            $allowedRoleType = $allowedRule['rolType'] ?? null;

            if (! is_string($allowedRoleType) || $allowedRoleType === '') {
                continue;
            }

            if (is_string($roleType) && $roleType !== '' && $roleType === $allowedRoleType) {
                return true;
            }

            if (! is_string($roleSelector) || $roleSelector === '') {
                continue;
            }

            $selectedRule = $this->metadataService->resolveRoleShapeRuleFromSelector($roleSelector, $roleShapeRules);
            $selectedRoleType = $selectedRule['rolType'] ?? null;
            if (is_string($selectedRoleType) && $selectedRoleType !== '' && $selectedRoleType === $allowedRoleType) {
                return true;
            }
        }

        return false;
    }

    private function toSparqlValue(mixed $value, string $type): string
    {
        if ($type === 'uri' && is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
                return "<{$trimmed}>";
            }
        }

        if ($type === 'integer') {
            $lexical = trim((string) $value);
            if (preg_match('/^-?\d+$/', $lexical)) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#integer');
            }
        }

        if ($type === 'decimal') {
            $lexical = trim((string) $value);
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $lexical)) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#decimal');
            }
        }

        if ($type === 'date') {
            $lexical = trim((string) $value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lexical)) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#date');
            }
        }

        if ($type === 'dateTime') {
            $lexical = $this->normalizeDateTimeLexical((string) $value);
            if ($lexical !== null) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#dateTime');
            }
        }

        return $this->toSparqlLiteral($value);
    }

    private function toSparqlTypedLiteral(string $value, string $datatypeIri): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );

        return "\"{$escaped}\"^^<{$datatypeIri}>";
    }

    private function normalizeDateTimeLexical(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace(' ', 'T', $trimmed);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            return $normalized.'T00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $normalized)) {
            return $normalized.':00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}Z$/', $normalized)) {
            return substr($normalized, 0, 16).':00Z';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $normalized)) {
            return substr($normalized, 0, 16).':00'.substr($normalized, 16);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $normalized)) {
            return $normalized;
        }

        return null;
    }

    private function resolveValueType(?string $explicitType, ?string $hintType): string
    {
        if ($explicitType === 'uri') {
            return $explicitType;
        }

        if (is_string($explicitType) && in_array($explicitType, ['integer', 'decimal', 'date', 'dateTime'], true)) {
            return $explicitType;
        }

        if (is_string($hintType) && in_array($hintType, ['uri', 'integer', 'decimal', 'date', 'dateTime'], true)) {
            return $hintType;
        }

        if ($explicitType === 'literal') {
            return 'literal';
        }

        return 'literal';
    }

    private function fetchTargetClassLimitForTransactie(int $transactieSoortId): ?array
    {
        $row = DB::table('transactie_soorten')
            ->where('id', $transactieSoortId)
            ->first(['max_target_class_uri', 'max_target_class_count']);

        $targetClassUri = $row->max_target_class_uri ?? null;
        $maxCount = isset($row->max_target_class_count) ? (int) $row->max_target_class_count : null;

        if (! is_string($targetClassUri) || trim($targetClassUri) === '' || ! is_int($maxCount) || $maxCount < 1) {
            return null;
        }

        return [
            'target_class_uri' => trim($targetClassUri),
            'max_count' => $maxCount,
        ];
    }

    private function countGoicsForCaseByDescribedClass(int $caseId, string $describedClassUri): int
    {
        $tbClasses = $this->metadataService->fetchTbClassesByDescribedClass($describedClassUri);
        if (empty($tbClasses)) {
            return 0;
        }

        $dossierIds = DB::table('dossiers')
            ->where('case_id', $caseId)
            ->pluck('id')
            ->all();
        if (empty($dossierIds)) {
            return 0;
        }

        return (int) DB::table('object_mutaties')
            ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.id', '=', 'object_mutaties.gegevens_object_in_context_id')
            ->whereIn('gegevens_objecten_in_context.dossier_id', $dossierIds)
            ->whereIn('object_mutaties.sjabloon_uri', $tbClasses)
            ->whereNotNull('object_mutaties.gegevens_object_in_context_id')
            ->distinct()
            ->count('object_mutaties.gegevens_object_in_context_id');
    }

    private function collectIdentityEntriesForObjects(array $objects, array $identityRulesByTbClass): array
    {
        $entries = [];
        foreach ($objects as $object) {
            $entry = $this->resolveObjectIdentityEntry($object, $identityRulesByTbClass);
            if (! is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $entries[$key] = $entry;
        }

        return array_values($entries);
    }

    private function resolveObjectIdentityEntry(array $object, array $identityRulesByTbClass): ?array
    {
        $tbClass = $object['sjabloon_uri'] ?? null;
        if (! is_string($tbClass) || $tbClass === '') {
            return null;
        }

        $rules = $identityRulesByTbClass[$tbClass] ?? [];
        if (! is_array($rules) || empty($rules)) {
            return null;
        }

        $data = $object['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        foreach ($rules as $rule) {
            $property = $rule['property'] ?? null;
            if (! is_string($property) || $property === '') {
                continue;
            }

            $rawValue = $data[$property] ?? null;
            if (! is_scalar($rawValue)) {
                continue;
            }

            $normalizer = strtoupper(trim((string) ($rule['normalizer'] ?? 'NONE')));
            $normalizedValue = $this->normalizeIdentityValue((string) $rawValue, $normalizer);
            if (! is_string($normalizedValue) || $normalizedValue === '') {
                continue;
            }

            return [
                'key' => $this->buildIdentityCacheKey($tbClass, $property, $normalizer, $normalizedValue),
                'tb_class' => $tbClass,
                'property' => $property,
                'normalizer' => $normalizer,
                'normalized_value' => $normalizedValue,
            ];
        }

        return null;
    }

    private function fetchExistingGoByIdentityEntries(array $identityEntries): array
    {
        if (empty($identityEntries)) {
            return [];
        }

        $entriesByRule = [];
        foreach ($identityEntries as $entry) {
            $tbClass = $entry['tb_class'] ?? null;
            $property = $entry['property'] ?? null;
            $normalizer = $entry['normalizer'] ?? 'NONE';
            $normalizedValue = $entry['normalized_value'] ?? null;
            if (! is_string($tbClass) || $tbClass === '' || ! is_string($property) || $property === '') {
                continue;
            }
            if (! is_string($normalizedValue) || $normalizedValue === '') {
                continue;
            }

            $ruleKey = $tbClass.'|'.$property.'|'.$normalizer;
            if (! isset($entriesByRule[$ruleKey])) {
                $entriesByRule[$ruleKey] = [
                    'tb_class' => $tbClass,
                    'property' => $property,
                    'normalizer' => $normalizer,
                    'values' => [],
                ];
            }
            $entriesByRule[$ruleKey]['values'][$normalizedValue] = true;
        }

        if (empty($entriesByRule)) {
            return [];
        }

        $goByIdentityKey = [];

        foreach ($entriesByRule as $rule) {
            $tbClass = $rule['tb_class'];
            $property = $rule['property'];
            $normalizer = $rule['normalizer'];
            $wantedValues = $rule['values'];

            $query = "
                PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
                SELECT ?rawValue ?go ?goic
                WHERE {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        ?tb a <{$tbClass}> ;
                            <{$property}> ?rawValue ;
                            vwm:beschrijftGOIC ?goic .
                        ?goic vwm:beschrijftGO ?go .
                    }
                }
                ORDER BY ?goic
            ";

            $rows = $this->graphService->query($query);
            foreach ($rows as $row) {
                $rawValue = $row['rawValue'] ?? null;
                $goUri = $row['go'] ?? null;
                if (! is_string($rawValue) || $rawValue === '' || ! is_string($goUri) || $goUri === '') {
                    continue;
                }

                $normalizedValue = $this->normalizeIdentityValue($rawValue, $normalizer);
                if (! is_string($normalizedValue) || $normalizedValue === '' || ! isset($wantedValues[$normalizedValue])) {
                    continue;
                }

                $cacheKey = $this->buildIdentityCacheKey($tbClass, $property, $normalizer, $normalizedValue);
                if (! isset($goByIdentityKey[$cacheKey])) {
                    $goByIdentityKey[$cacheKey] = $goUri;
                }
            }
        }

        return $goByIdentityKey;
    }

    private function normalizeIdentityValue(string $value, string $normalizer): ?string
    {
        $strategy = strtoupper(trim($normalizer));
        $source = trim($value);
        if ($source === '') {
            return null;
        }

        return match ($strategy) {
            'ALNUM_UPPER' => ($normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $source) ?? '')) !== '' ? $normalized : null,
            'DIGITS_ONLY' => ($normalized = preg_replace('/\D+/', '', $source) ?? '') !== '' ? $normalized : null,
            'UPPER_TRIM' => strtoupper($source),
            'LOWER_TRIM' => strtolower($source),
            'NONE', 'TRIM', '' => $source,
            default => $source,
        };
    }

    private function buildIdentityCacheKey(string $tbClass, string $property, string $normalizer, string $normalizedValue): string
    {
        return $tbClass.'|'.$property.'|'.$normalizer.'|'.$normalizedValue;
    }

    private function extractLegacyRoleEndpoints(array $role): array
    {
        $fromId = isset($role['fromId']) && is_string($role['fromId']) && $role['fromId'] !== ''
            ? $role['fromId']
            : null;
        $toId = isset($role['toId']) && is_string($role['toId']) && $role['toId'] !== ''
            ? $role['toId']
            : null;

        $idValues = [];
        foreach ($role as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, 'Id')) {
                continue;
            }
            if (! is_string($value) || $value === '') {
                continue;
            }
            $idValues[] = $value;
        }

        if ($fromId === null && ! empty($idValues)) {
            $fromId = $idValues[0] ?? null;
        }

        if ($toId === null && count($idValues) > 1) {
            $toId = $idValues[1] ?? null;
        }

        return [$fromId, $toId];
    }
}
