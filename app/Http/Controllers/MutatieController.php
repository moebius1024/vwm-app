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
        $mode = (string) $request->input('mode', 'register');

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

        $roleShapeRules = $this->metadataService->fetchRoleShapeRules();
        if ($mode === 'delete') {
            return $this->deleteToestandMutatie($request, $base, (int) $dossier->id, $userId, $roleShapeRules);
        }

        $relatieRegels = $this->metadataService->fetchRelatieRegels();
        $rolTypesByKey = $this->metadataService->fetchRolTypesByKey();
        $allowedRoleRows = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $base['transactie_soort_id'])
            ->where('type', 'rol')
            ->orderBy('volgorde')
            ->get(['sjabloon_uri', 'crud_flags'])
            ->all();
        $allowedRoleSelectors = array_values(array_filter(array_map(fn ($row) => $row->sjabloon_uri ?? null, $allowedRoleRows)));
        $roleCrudBySelector = [];
        foreach ($allowedRoleRows as $row) {
            if (! is_string($row->sjabloon_uri ?? null) || $row->sjabloon_uri === '') {
                continue;
            }
            $roleCrudBySelector[$row->sjabloon_uri] = strtoupper((string) ($row->crud_flags ?? 'CRD'));
        }
        $enforceAllowedRole = ! empty($allowedRoleSelectors);

        // 2. Ondersteun meerdere objecten per scherm (en legacy single-object payload)
        $objects = $request->input('objects');
        $rolesInput = $request->input('roles', []);
        $roleItemsInput = is_array($rolesInput['items'] ?? null) ? $rolesInput['items'] : [];
        $hasRoleItems = count($roleItemsInput) > 0;

        if (empty($objects) && ! $hasRoleItems) {
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
        } elseif (! empty($objects)) {
            $request->validate([
                'objects' => 'required|array|min:1',
                'objects.*.client_id' => 'required|string',
                'objects.*.sjabloon_uri' => 'required|string',
                'objects.*.target_class' => 'required|string',
                'objects.*.attach_to_existing' => 'sometimes|boolean',
                'objects.*.existing_goic_id' => 'sometimes|nullable|integer',
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
        } else {
            $objects = [];
            $request->validate([
                'roles' => 'required|array',
                'roles.items' => 'required|array|min:1',
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
        $mutationTargetMeta = null;
        if ($mode === 'mutate') {
            $target = $request->validate([
                'target' => 'required|array',
                'target.goic_id' => 'required|integer',
                'target.mutatie_id' => 'required|integer',
                'target.tb_rdf_uri' => 'nullable|string',
                'target.sjabloon_uri' => 'nullable|string',
            ])['target'];

            $mutationTargetMeta = DB::table('object_mutaties')
                ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.id', '=', 'object_mutaties.gegevens_object_in_context_id')
                ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
                ->where('object_mutaties.id', (int) $target['mutatie_id'])
                ->where('gegevens_objecten_in_context.id', (int) $target['goic_id'])
                ->where('gegevens_objecten_in_context.dossier_id', (int) $dossier->id)
                ->first([
                    'object_mutaties.id as mutatie_id',
                    'object_mutaties.gegevens_object_in_context_id as goic_id',
                    'gegevens_objecten_in_context.rdf_uri as goic_uri',
                    'object_mutaties.sjabloon_uri as tb_class',
                    'toestands_beschrijvingen.id as tb_id',
                    'toestands_beschrijvingen.rdf_uri as tb_uri',
                ]);

            if (! $mutationTargetMeta || ! is_string($mutationTargetMeta->tb_uri) || $mutationTargetMeta->tb_uri === '') {
                return response()->json(['error' => 'Mutatiedoel niet gevonden of ongeldig.'], 422);
            }
        }
        $describedClassByTbClass = $this->metadataService->fetchDescribedClassByTbClasses($tbClasses);
        $allowedSjabloonCrud = $this->fetchAllowedSjabloonCrudByTbClass((int) $base['transactie_soort_id']);

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

            $crudFlags = $allowedSjabloonCrud[$tbClass] ?? null;
            $isToestandsWeergave = $this->isToestandsWeergaveTbClass((string) $tbClass);
            $existingGoicId = isset($object['existing_goic_id']) ? (int) $object['existing_goic_id'] : null;
            $attachRequested = ! empty($object['attach_to_existing']);
            $isAttachOnlySjabloon = $this->hasCrud($crudFlags, 'A') && ! $this->hasCrud($crudFlags, 'C');
            $isAttachIntent = $attachRequested || ($existingGoicId !== null && $existingGoicId > 0) || $isToestandsWeergave || $isAttachOnlySjabloon;

            $object['attach_to_existing'] = $isAttachIntent;

            if ($mode !== 'mutate') {
                $attachAllowed = $this->hasCrud($crudFlags, 'A') || ($isToestandsWeergave && $this->hasCrud($crudFlags, 'C'));

                if ($isAttachIntent && ! $attachAllowed) {
                    return response()->json([
                        'error' => "Toevoegen op bestaand object niet toegestaan voor sjabloon {$tbClass} in deze transactie.",
                    ], 422);
                }

                if (! $isAttachIntent && ! $this->hasCrud($crudFlags, 'C')) {
                    return response()->json([
                        'error' => "Aanmaken niet toegestaan voor sjabloon {$tbClass} in deze transactie.",
                    ], 422);
                }
            }
        }
        unset($object);

        if ($mode === 'mutate' && $mutationTargetMeta) {
            $tbClass = (string) ($mutationTargetMeta->tb_class ?? '');
            if ($tbClass === '') {
                return response()->json(['error' => 'Mutatiedoel heeft een onbekende class.'], 422);
            }
            if (! $this->hasCrud($allowedSjabloonCrud[$tbClass] ?? null, 'U')) {
                return response()->json([
                    'error' => "Muteren niet toegestaan voor sjabloon {$tbClass} in deze transactie.",
                ], 422);
            }
        }

        $goicTargetClassMap = $this->getGoicTargetClassMapForCase($base['case_id']);
        $goicUriById = [];
        if (! empty($goicTargetClassMap)) {
            $goicUriById = DB::table('gegevens_objecten_in_context')
                ->whereIn('id', array_keys($goicTargetClassMap))
                ->pluck('rdf_uri', 'id')
                ->all();
        }
        $goicIdsByClass = [];
        foreach ($goicTargetClassMap as $goicId => $classUri) {
            if (! isset($goicIdsByClass[$classUri])) {
                $goicIdsByClass[$classUri] = [];
            }
            $goicIdsByClass[$classUri][] = $goicId;
        }

        foreach ($objects as &$object) {
            $tbClass = (string) ($object['sjabloon_uri'] ?? '');
            $targetClass = (string) ($object['target_class'] ?? '');
            $existingGoicId = isset($object['existing_goic_id']) ? (int) $object['existing_goic_id'] : null;
            $attachToExisting = ! empty($object['attach_to_existing']);
            $isToestandsWeergave = $this->isToestandsWeergaveTbClass($tbClass);
            $candidateGoicIds = $goicIdsByClass[$targetClass] ?? [];

            // In mutatiemodus schrijven we altijd op het gekozen bestaande GOIC.
            if ($mode === 'mutate' && $mutationTargetMeta) {
                $targetGoicId = (int) $mutationTargetMeta->goic_id;
                $targetGoicClass = $goicTargetClassMap[$targetGoicId] ?? null;
                if (! is_string($targetGoicClass) || $targetGoicClass !== $targetClass) {
                    return response()->json([
                        'error' => "Mutatiedoel hoort niet bij target_class {$targetClass}.",
                    ], 422);
                }

                $object['existing_goic_id'] = $targetGoicId;
                continue;
            }

            if ($existingGoicId !== null && $existingGoicId > 0) {
                $existingClass = $goicTargetClassMap[$existingGoicId] ?? null;
                if (! is_string($existingClass)) {
                    return response()->json([
                        'error' => 'Geselecteerd bestaand object hoort niet bij deze case.',
                    ], 422);
                }

                if ($existingClass !== $targetClass) {
                    return response()->json([
                        'error' => "Geselecteerd object heeft class {$existingClass}, verwacht {$targetClass}.",
                    ], 422);
                }

                if (! $isToestandsWeergave && ! $attachToExisting) {
                    return response()->json([
                        'error' => "Bestaand object koppelen is niet toegestaan voor sjabloon {$tbClass}.",
                    ], 422);
                }

                if ($this->isPersoonsBeschrijvingTbClass($tbClass)) {
                    $existingGoicUri = $goicUriById[$existingGoicId] ?? null;
                    if (! is_string($existingGoicUri) || $existingGoicUri === '') {
                        return response()->json([
                            'error' => 'Kon bestaand GOIC niet resolven.',
                        ], 422);
                    }

                    $attachCheck = $this->evaluateBeschrijvingAttachEligibility((string) $existingGoicUri, $targetClass);
                    if (! $attachCheck['has_signalement']) {
                        return response()->json([
                            'error' => 'PersoonsBeschrijving toevoegen kan alleen op een object met actief signalement.',
                        ], 422);
                    }
                    if ($attachCheck['has_beschrijving']) {
                        return response()->json([
                            'error' => 'Dit object heeft al een actieve beschrijving.',
                        ], 422);
                    }
                }

                $object['existing_goic_id'] = $existingGoicId;
                continue;
            }

            if ($attachToExisting) {
                if ($this->isPersoonsBeschrijvingTbClass($tbClass)) {
                    return response()->json([
                        'error' => "Kies eerst op welk bestaand object ({$targetClass}) je deze beschrijving wilt registreren.",
                    ], 422);
                }

                if (count($candidateGoicIds) === 1) {
                    $object['existing_goic_id'] = $candidateGoicIds[0];
                    continue;
                }

                if (count($candidateGoicIds) > 1) {
                    return response()->json([
                        'error' => "Kies eerst op welk bestaand object ({$targetClass}) je deze registratie wilt uitvoeren.",
                    ], 422);
                }

                return response()->json([
                    'error' => "Geen bestaand object ({$targetClass}) gevonden in dit dossier voor deze registratie.",
                ], 422);
            }

            if ($isToestandsWeergave) {
                if (count($candidateGoicIds) === 1) {
                    $object['existing_goic_id'] = $candidateGoicIds[0];
                    continue;
                }

                if (count($candidateGoicIds) > 1) {
                    return response()->json([
                        'error' => "Kies eerst op welk bestaand object ({$targetClass}) je deze toestandsweergave wilt registreren.",
                    ], 422);
                }

                return response()->json([
                    'error' => "Geen bestaand object ({$targetClass}) gevonden in dit dossier voor deze toestandsweergave.",
                ], 422);
            }

            $object['existing_goic_id'] = null;
        }
        unset($object);

        $valueHintsByTbClass = $this->metadataService->fetchPropertyValueHintsByTbClasses($tbClasses);

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
            $transactieId = DB::transaction(function () use ($base, $objects, &$objectUris, &$allTriples, &$objectMeta, $dossier, $nowIso, $vwm, $valueHintsByTbClass, $identityRulesByTbClass, $existingGoByIdentityKey, $userId, $mode, $mutationTargetMeta) {
                $transactieId = DB::table('transacties')->insertGetId([
                    'case_id' => $base['case_id'],
                    'transactie_soort_id' => $base['transactie_soort_id'],
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $goByIdentityKey = $existingGoByIdentityKey;

                if ($mode === 'mutate' && $mutationTargetMeta) {
                    $invalidateMutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
                    DB::table('object_mutaties')->insert([
                        'transactie_id' => $transactieId,
                        'sjabloon_uri' => (string) ($mutationTargetMeta->tb_class ?? ''),
                        'object_uri' => (string) $mutationTargetMeta->tb_uri,
                        'gegevens_object_in_context_id' => (int) $mutationTargetMeta->goic_id,
                        'geproduceerde_toestand_id' => null,
                        'verwijderde_toestand_id' => isset($mutationTargetMeta->tb_id) ? (int) $mutationTargetMeta->tb_id : null,
                        'datum_tijd' => now(),
                        'data' => json_encode([
                            'actie' => 'beeindig_toestand',
                            'tb_uri' => (string) $mutationTargetMeta->tb_uri,
                            'invalidatedAtTime' => $nowIso,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $allTriples .= "<{$mutationTargetMeta->tb_uri}> <http://ontologie.politie.nl/def/dpm#invalidatedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";
                    $allTriples .= "<{$invalidateMutatieUri}> a <{$vwm}ObjectMutatie> . \n";
                    $allTriples .= "<{$invalidateMutatieUri}> <{$vwm}heeftBetrekkingOp> <{$mutationTargetMeta->goic_uri}> . \n";
                    $allTriples .= "<{$invalidateMutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";
                }

                foreach ($objects as $object) {
                    $tbClass = $object['sjabloon_uri']; // TB-class (bijv. vwm:PersoonsBeschrijving)
                    $describedClass = $object['target_class']; // Domeinclass (bijv. dpm:Person)
                    $existingGoicId = isset($object['existing_goic_id']) ? (int) $object['existing_goic_id'] : null;

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

                    $tbUuid = (string) Str::uuid();
                    $mutatieUuid = (string) Str::uuid();

                    $goicUuid = null;
                    $goicUri = null;
                    $tbUri = 'http://vwm.voorbeeld.nl/data/tb/'.$tbUuid;
                    $mutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.$mutatieUuid;

                    $objectUris[] = $tbUri;

                    if ($existingGoicId !== null && $existingGoicId > 0) {
                        $existingGoic = DB::table('gegevens_objecten_in_context')
                            ->where('id', $existingGoicId)
                            ->where('dossier_id', $dossier->id)
                            ->first(['id', 'rdf_uri']);

                        if (! $existingGoic) {
                            throw new \RuntimeException('Bestaand GOIC niet gevonden in dit dossier.');
                        }

                        $goicId = (int) $existingGoic->id;
                        $goicUri = (string) $existingGoic->rdf_uri;
                    } else {
                        $goicUuid = (string) Str::uuid();
                        $goicUri = 'http://vwm.voorbeeld.nl/data/goic/'.$goicUuid;

                        $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
                            'uuid' => $goicUuid,
                            'rdf_uri' => $goicUri,
                            'dossier_id' => $dossier->id,
                            'context_data' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

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
                    if ($existingGoicId === null || $existingGoicId <= 0) {
                        $allTriples .= "<{$goUri}> a <{$vwm}GegevensObject> . \n";
                        $allTriples .= "<{$goicUri}> a <{$vwm}GegevensObjectInContext> . \n";
                        $allTriples .= "<{$goicUri}> <{$vwm}beschrijftGO> <{$goUri}> . \n";
                        $allTriples .= "<{$goicUri}> <{$vwm}heeftDoelClass> <{$describedClass}> . \n";
                        $allTriples .= "<{$goicUri}> <{$vwm}hoortBijDossier> <{$dossier->rdf_uri}> . \n";
                    }
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

            $roleItems = $this->appendAutoRoleItems(
                $roleItems,
                $objects,
                $objectMeta,
                $goicByClass,
                $roleShapeRules
            );

            foreach ($roleItems as $roleItem) {
                $roleType = $roleItem['roleType'] ?? null;
                $roleTbClass = $roleItem['roleTbClass'] ?? null;
                $fromId = $roleItem['fromId'] ?? null;
                $toId = $roleItem['toId'] ?? null;
                $fromGoicId = $roleItem['fromGoicId'] ?? null;
                $toGoicId = $roleItem['toGoicId'] ?? null;
                $toUri = $roleItem['toUri'] ?? null;
                $isAutoRole = (bool) ($roleItem['isAuto'] ?? false);

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
                if (! $isAutoRole && ! $this->isRoleCreateAllowed($roleType, $roleTbClass, $roleCrudBySelector, $roleShapeRules)) {
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
                if (is_string($toUri) && $toUri !== '') {
                    $targetGoics[] = $toUri;
                } elseif (! empty($toGoicId) && ! empty($goicMetaById[$toGoicId])) {
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
     * 1) maak GOIC aan
     * 2) koppel aan dezelfde GO
     * 3) leg DataObjectAssociation vast
     * 4) leg stap 1 en 3 vast als object_mutaties in SQLite
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

        return [
            'go_uri' => $rows[0]['go'] ?? null,
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

        // Als frontend alleen "literal" meestuurt, maar SHACL/metadata een
        // specifieker type kent, dan volgen we de SHACL-hint.
        if ($explicitType === 'literal' && is_string($hintType) && in_array($hintType, ['integer', 'decimal', 'date', 'dateTime'], true)) {
            return $hintType;
        }

        if (is_string($hintType) && in_array($hintType, ['uri', 'integer', 'decimal', 'date', 'dateTime'], true)) {
            return $hintType;
        }

        if ($explicitType === 'literal') {
            return 'literal';
        }

        return 'literal';
    }

    private function isToestandsWeergaveTbClass(string $tbClassUri): bool
    {
        return str_contains($tbClassUri, 'ToestandsWeergave');
    }

    private function isPersoonsBeschrijvingTbClass(string $tbClassUri): bool
    {
        return Str::endsWith($tbClassUri, 'PersoonsBeschrijving');
    }

    private function isSignalementTbClass(string $tbClassUri): bool
    {
        return Str::endsWith($tbClassUri, 'Signalement');
    }

    private function isBeschrijvingTbClass(string $tbClassUri): bool
    {
        return Str::endsWith($tbClassUri, 'Beschrijving');
    }

    /**
     * @return array{has_signalement:bool,has_beschrijving:bool}
     */
    private function evaluateBeschrijvingAttachEligibility(string $goicUri, string $targetClass): array
    {
        $activeRows = $this->fetchActiveTbRowsForGoic($goicUri);
        $tbClasses = [];
        foreach ($activeRows as $row) {
            $tbClass = (string) ($row['tb_class'] ?? '');
            if ($tbClass !== '') {
                $tbClasses[$tbClass] = true;
            }
        }
        $classUris = array_keys($tbClasses);
        if (empty($classUris)) {
            return ['has_signalement' => false, 'has_beschrijving' => false];
        }

        $describedByTb = $this->metadataService->fetchDescribedClassByTbClasses($classUris);
        $hasSignalement = false;
        $hasBeschrijving = false;

        foreach ($classUris as $tbClass) {
            $describedClass = $describedByTb[$tbClass] ?? null;
            if (! is_string($describedClass) || $describedClass !== $targetClass) {
                continue;
            }

            if ($this->isSignalementTbClass($tbClass)) {
                $hasSignalement = true;
            }
            if ($this->isBeschrijvingTbClass($tbClass)) {
                $hasBeschrijving = true;
            }
        }

        return [
            'has_signalement' => $hasSignalement,
            'has_beschrijving' => $hasBeschrijving,
        ];
    }

    private function getGoicTargetClassMapForCase(int $caseId): array
    {
        $dossierIds = DB::table('dossiers')
            ->where('case_id', $caseId)
            ->pluck('id')
            ->all();

        if (empty($dossierIds)) {
            return [];
        }

        $goics = DB::table('gegevens_objecten_in_context')
            ->whereIn('dossier_id', $dossierIds)
            ->get(['id'])
            ->all();

        if (empty($goics)) {
            return [];
        }

        $goicIds = array_map(fn ($row) => (int) $row->id, $goics);

        $tbRows = DB::table('object_mutaties')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->whereIn('object_mutaties.gegevens_object_in_context_id', $goicIds)
            ->orderBy('object_mutaties.created_at')
            ->orderBy('object_mutaties.id')
            ->get([
                'object_mutaties.gegevens_object_in_context_id as goic_id',
                'toestands_beschrijvingen.beschrijving as tb_class',
            ]);

        $tbHistoryByGoic = [];
        $tbClassesInUse = [];
        foreach ($tbRows as $row) {
            if (! empty($row->tb_class)) {
                $tbHistoryByGoic[(int) $row->goic_id][] = (string) $row->tb_class;
                $tbClassesInUse[(string) $row->tb_class] = true;
            }
        }

        if (empty($tbClassesInUse)) {
            return [];
        }

        $describedByTb = $this->metadataService->fetchDescribedClassByTbClasses(array_keys($tbClassesInUse));
        $map = [];

        foreach ($goicIds as $goicId) {
            $tbHistory = $tbHistoryByGoic[$goicId] ?? [];
            for ($index = count($tbHistory) - 1; $index >= 0; $index--) {
                $tbClass = $tbHistory[$index];
                $candidateTargetClass = $describedByTb[$tbClass] ?? null;
                if (is_string($candidateTargetClass) && $candidateTargetClass !== '') {
                    $map[$goicId] = $candidateTargetClass;
                    break;
                }
            }
        }

        return $map;
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

    private function deleteToestandMutatie(Request $request, array $base, int $dossierId, int $userId, array $roleShapeRules)
    {
        $payload = $request->validate([
            'delete_type' => 'required|string|in:role,toestand',
            'target' => 'required|array',
            'target.goic_id' => 'required|integer',
            'target.mutatie_id' => 'required|integer',
            'target.tb_rdf_uri' => 'nullable|string',
            'target.sjabloon_uri' => 'nullable|string',
        ]);

        $target = $payload['target'];
        $targetRow = DB::table('object_mutaties')
            ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.id', '=', 'object_mutaties.gegevens_object_in_context_id')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->where('object_mutaties.id', (int) $target['mutatie_id'])
            ->where('gegevens_objecten_in_context.id', (int) $target['goic_id'])
            ->where('gegevens_objecten_in_context.dossier_id', $dossierId)
            ->first([
                'object_mutaties.id as mutatie_id',
                'object_mutaties.sjabloon_uri as tb_class',
                'toestands_beschrijvingen.id as tb_id',
                'toestands_beschrijvingen.rdf_uri as tb_uri',
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
            ]);

        if (! $targetRow || ! is_string($targetRow->tb_uri) || $targetRow->tb_uri === '') {
            return response()->json(['error' => 'Doel voor verwijderen niet gevonden.'], 422);
        }
        $deleteType = (string) ($payload['delete_type'] ?? '');
        if ($deleteType === 'role') {
            if (! $this->isRoleDeleteAllowed((int) $base['transactie_soort_id'], (string) ($targetRow->tb_class ?? ''), $roleShapeRules)) {
                return response()->json(['error' => 'Verwijderen niet toegestaan voor deze rol in deze transactie.'], 422);
            }
        } else {
            if ($this->isRoleTbClass((string) ($targetRow->tb_class ?? ''), $roleShapeRules)) {
                return response()->json(['error' => 'Gebruik rol-verwijderen voor roltoestanden.'], 422);
            }
            if (! $this->isClassDeleteAllowed((int) $base['transactie_soort_id'], (string) ($targetRow->tb_class ?? ''))) {
                return response()->json(['error' => 'Verwijderen niet toegestaan voor dit sjabloon in deze transactie.'], 422);
            }
        }

        $now = now();
        $nowIso = $now->toAtomString();
        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $dpm = 'http://ontologie.politie.nl/def/dpm#';

        DB::beginTransaction();
        try {
            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => $base['case_id'],
                'transactie_soort_id' => $base['transactie_soort_id'],
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $toInvalidate = [[
                'tb_uri' => (string) $targetRow->tb_uri,
                'tb_class' => (string) ($targetRow->tb_class ?? ''),
                'tb_id' => isset($targetRow->tb_id) ? (int) $targetRow->tb_id : null,
            ]];

            // Cascade: wanneer een GOIC geen actieve kern-TB meer heeft, invalideren we ook
            // alle actieve rol-TB's en ToestandsWeergaves op die GOIC.
            if ($deleteType === 'toestand') {
                $activeTbRows = $this->fetchActiveTbRowsForGoic((string) $targetRow->goic_uri);
                $remainingAfterDelete = array_values(array_filter($activeTbRows, function (array $row) use ($targetRow) {
                    return ($row['tb_uri'] ?? '') !== (string) $targetRow->tb_uri;
                }));

                $invalidationRules = $this->metadataService->fetchAutoRoleInvalidationRules();
                $extraRoleUris = [];
                foreach ($invalidationRules as $rule) {
                    $triggerTbClass = (string) ($rule['triggerTbClass'] ?? '');
                    $rolType = (string) ($rule['rolType'] ?? '');
                    if ($triggerTbClass === '' || $rolType === '' || $triggerTbClass !== (string) ($targetRow->tb_class ?? '')) {
                        continue;
                    }

                    $shapeRule = $roleShapeRules[$rolType] ?? null;
                    if (! is_array($shapeRule)) {
                        continue;
                    }

                    $roleTbClass = (string) ($shapeRule['rolTbClass'] ?? '');
                    $fromProperty = (string) ($shapeRule['vanProperty'] ?? '');
                    if ($roleTbClass === '' || $fromProperty === '') {
                        continue;
                    }

                    $uris = $this->fetchActiveRoleTbUrisByRoleTypeAndSourceGoic(
                        (string) $targetRow->goic_uri,
                        $roleTbClass,
                        $rolType,
                        $fromProperty
                    );
                    foreach ($uris as $uri) {
                        $extraRoleUris[$uri] = $roleTbClass;
                    }
                }

                if (! empty($extraRoleUris)) {
                    $tbIdByUri = $this->fetchTbIdsByUris(array_keys($extraRoleUris));
                    foreach ($extraRoleUris as $uri => $tbClass) {
                        $toInvalidate[] = [
                            'tb_uri' => (string) $uri,
                            'tb_class' => (string) $tbClass,
                            'tb_id' => $tbIdByUri[$uri] ?? null,
                        ];
                    }
                }

                $remainingKernel = array_values(array_filter($remainingAfterDelete, function (array $row) use ($roleShapeRules) {
                    $tbClass = (string) ($row['tb_class'] ?? '');
                    if ($tbClass === '') {
                        return false;
                    }
                    if ($this->isRoleTbClass($tbClass, $roleShapeRules)) {
                        return false;
                    }
                    if ($this->isToestandsWeergaveTbClass($tbClass)) {
                        return false;
                    }
                    if (str_contains(strtolower($tbClass), 'dataobjectassociation')) {
                        return false;
                    }
                    return true;
                }));

                if (count($remainingKernel) === 0) {
                    $cascadeRows = array_values(array_filter($remainingAfterDelete, function (array $row) use ($roleShapeRules) {
                        $tbClass = (string) ($row['tb_class'] ?? '');
                        if ($tbClass === '') {
                            return false;
                        }
                        return $this->isRoleTbClass($tbClass, $roleShapeRules) || $this->isToestandsWeergaveTbClass($tbClass);
                    }));

                    if (! empty($cascadeRows)) {
                        $tbUris = array_values(array_unique(array_map(fn ($row) => (string) ($row['tb_uri'] ?? ''), $cascadeRows)));
                        $tbIdByUri = $this->fetchTbIdsByUris($tbUris);
                        foreach ($cascadeRows as $row) {
                            $uri = (string) ($row['tb_uri'] ?? '');
                            if ($uri === '') {
                                continue;
                            }
                            $toInvalidate[] = [
                                'tb_uri' => $uri,
                                'tb_class' => (string) ($row['tb_class'] ?? ''),
                                'tb_id' => $tbIdByUri[$uri] ?? null,
                            ];
                        }
                    }
                }
            }

            $triples = '';
            $seenTbUris = [];
            foreach ($toInvalidate as $item) {
                $tbUri = (string) ($item['tb_uri'] ?? '');
                if ($tbUri === '' || isset($seenTbUris[$tbUri])) {
                    continue;
                }
                $seenTbUris[$tbUri] = true;
                DB::table('object_mutaties')->insert([
                    'transactie_id' => $transactieId,
                    'sjabloon_uri' => (string) ($item['tb_class'] ?? ''),
                    'object_uri' => $tbUri,
                    'gegevens_object_in_context_id' => (int) $targetRow->goic_id,
                    'geproduceerde_toestand_id' => null,
                    'verwijderde_toestand_id' => isset($item['tb_id']) ? (int) $item['tb_id'] : null,
                    'datum_tijd' => $now,
                    'data' => json_encode([
                        'actie' => 'beeindig_toestand',
                        'tb_uri' => $tbUri,
                        'invalidatedAtTime' => $nowIso,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $mutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
                $triples .= "<{$tbUri}> <{$dpm}invalidatedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";
                $triples .= "<{$mutatieUri}> a <{$vwm}ObjectMutatie> .\n";
                $triples .= "<{$mutatieUri}> <{$vwm}heeftBetrekkingOp> <{$targetRow->goic_uri}> .\n";
                $triples .= "<{$mutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";
            }

            $this->graphService->update("
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        {$triples}
                    }
                }
            ");

            DB::commit();

            return response()->json([
                'ok' => true,
                'mode' => 'delete',
                'message' => $deleteType === 'role' ? 'Rol verwijderd.' : 'Toestand verwijderd.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Verwijderen mislukt.',
                'details' => $e->getMessage(),
            ], 500);
        }
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

    private function hasCrud(?string $flags, string $required): bool
    {
        return str_contains(strtoupper((string) ($flags ?? 'CRUD')), strtoupper($required));
    }

    private function fetchAllowedSjabloonCrudByTbClass(int $transactieSoortId): array
    {
        $rows = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'sjabloon')
            ->get(['sjabloon_uri', 'crud_flags'])
            ->all();

        $crudByTbClass = [];
        foreach ($rows as $row) {
            $uri = $row->sjabloon_uri ?? null;
            if (! is_string($uri) || $uri === '') {
                continue;
            }
            $crudByTbClass[$uri] = strtoupper((string) ($row->crud_flags ?? 'CRUD'));
        }

        return $crudByTbClass;
    }

    private function isRoleCreateAllowed(?string $roleType, ?string $roleSelector, array $roleCrudBySelector, array $roleShapeRules): bool
    {
        foreach ($roleCrudBySelector as $selector => $flags) {
            if (! $this->hasCrud($flags, 'C')) {
                continue;
            }
            if ($roleSelector === $selector || $roleType === $selector) {
                return true;
            }

            $rule = $this->metadataService->resolveRoleShapeRuleFromSelector($selector, $roleShapeRules);
            $candidateRoleTbClass = $rule['rolTbClass'] ?? null;
            $candidateRoleType = $rule['rolType'] ?? null;
            if (is_string($roleSelector) && $roleSelector !== '' && $roleSelector === $candidateRoleTbClass) {
                return true;
            }
            if (is_string($roleType) && $roleType !== '' && $roleType === $candidateRoleType) {
                return true;
            }
        }

        return empty($roleCrudBySelector);
    }

    private function isRoleDeleteAllowed(int $transactieSoortId, string $roleTbClass, array $roleShapeRules): bool
    {
        $rows = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'rol')
            ->get(['sjabloon_uri', 'crud_flags'])
            ->all();

        foreach ($rows as $row) {
            if (! $this->hasCrud((string) ($row->crud_flags ?? 'CRD'), 'D')) {
                continue;
            }
            $selector = $row->sjabloon_uri ?? null;
            if (! is_string($selector) || $selector === '') {
                continue;
            }
            if ($selector === $roleTbClass) {
                return true;
            }

            $rule = $this->metadataService->resolveRoleShapeRuleFromSelector($selector, $roleShapeRules);
            $candidateRoleTbClass = $rule['rolTbClass'] ?? null;
            if (is_string($candidateRoleTbClass) && $candidateRoleTbClass === $roleTbClass) {
                return true;
            }
        }

        return false;
    }

    private function isRoleTbClass(string $tbClass, array $roleShapeRules): bool
    {
        if ($tbClass === '') {
            return false;
        }

        foreach ($roleShapeRules as $rule) {
            $candidate = $rule['rolTbClass'] ?? null;
            if (is_string($candidate) && $candidate === $tbClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Voegt automatische role-items toe op basis van regels uit de ontologie/shapes.
     */
    private function appendAutoRoleItems(
        array $roleItems,
        array $objects,
        array $objectMeta,
        array $goicByClass,
        array $roleShapeRules
    ): array {
        $autoRoleRules = $this->metadataService->fetchAutoRoleRules();
        if (count($autoRoleRules) === 0) {
            return $roleItems;
        }

        $objectMetaByClientId = [];
        foreach ($objectMeta as $meta) {
            $clientId = $meta['client_id'] ?? null;
            if (is_string($clientId) && $clientId !== '') {
                $objectMetaByClientId[$clientId] = $meta;
            }
        }

        $newRoleItems = [];
        foreach ($objects as $object) {
            $tbClass = (string) ($object['sjabloon_uri'] ?? '');
            $clientId = (string) ($object['client_id'] ?? '');
            if ($clientId === '' || empty($objectMetaByClientId[$clientId])) {
                continue;
            }

            $fromMeta = $objectMetaByClientId[$clientId];
            $fromGoicId = isset($fromMeta['goic_id']) ? (int) $fromMeta['goic_id'] : 0;
            $fromClass = (string) ($fromMeta['target_class'] ?? '');
            if ($fromGoicId <= 0 || $fromClass === '') {
                continue;
            }

            foreach ($autoRoleRules as $rule) {
                $triggerTbClass = (string) ($rule['triggerTbClass'] ?? '');
                $rolType = (string) ($rule['rolType'] ?? '');
                if ($triggerTbClass === '' || $rolType === '' || $triggerTbClass !== $tbClass) {
                    continue;
                }

                $shapeRule = $roleShapeRules[$rolType] ?? null;
                if (! is_array($shapeRule)) {
                    continue;
                }

                $expectedFromClass = (string) ($shapeRule['vanClass'] ?? '');
                $targetClass = (string) ($shapeRule['naarClass'] ?? '');
                if ($expectedFromClass === '' || $targetClass === '' || $expectedFromClass !== $fromClass) {
                    continue;
                }

                $targetGoics = array_values(array_filter($goicByClass[$targetClass] ?? []));
                if (count($targetGoics) === 0) {
                    continue;
                }

                $newRoleItems[] = [
                    'roleType' => $rolType,
                    'fromGoicId' => $fromGoicId,
                    'toId' => null,
                    'toGoicId' => null,
                    'toUri' => $targetGoics[0],
                    'isAuto' => true,
                ];
            }
        }

        if (count($newRoleItems) === 0) {
            return $roleItems;
        }

        $existingKeys = [];
        foreach ($roleItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $existingKeys[$this->roleItemSignature($item)] = true;
        }

        foreach ($newRoleItems as $item) {
            $signature = $this->roleItemSignature($item);
            if (isset($existingKeys[$signature])) {
                continue;
            }
            $roleItems[] = $item;
            $existingKeys[$signature] = true;
        }

        return $roleItems;
    }

    private function roleItemSignature(array $roleItem): string
    {
        return implode('|', [
            (string) ($roleItem['roleType'] ?? ''),
            (string) ($roleItem['roleTbClass'] ?? ''),
            (string) ($roleItem['fromId'] ?? ''),
            (string) ($roleItem['fromGoicId'] ?? ''),
            (string) ($roleItem['toId'] ?? ''),
            (string) ($roleItem['toGoicId'] ?? ''),
            (string) ($roleItem['toUri'] ?? ''),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function fetchActiveRoleTbUrisByRoleTypeAndSourceGoic(
        string $sourceGoicUri,
        string $roleTbClass,
        string $roleType,
        string $fromProperty
    ): array {
        if ($sourceGoicUri === '' || $roleTbClass === '' || $roleType === '' || $fromProperty === '') {
            return [];
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT DISTINCT ?tb
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    ?tb a <{$roleTbClass}> ;
                        <{$fromProperty}> <{$sourceGoicUri}> ;
                        vwm:rolType <{$roleType}> .
                    FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $uris = [];
        foreach ($rows as $row) {
            $uri = $row['tb'] ?? null;
            if (is_string($uri) && $uri !== '') {
                $uris[] = $uri;
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return array<int, array{tb_uri:string,tb_class:string|null}>
     */
    private function fetchActiveTbRowsForGoic(string $goicUri): array
    {
        if ($goicUri === '') {
            return [];
        }

        $query = "
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT DISTINCT ?tb ?tbClass
            WHERE {
                {
                    ?tb vwm:beschrijftGOIC <{$goicUri}> .
                }
                UNION
                {
                    ?mutatie a vwm:ObjectMutatie ;
                             vwm:heeftBetrekkingOp <{$goicUri}> ;
                             vwm:produceert ?tb .
                }
                ?tb rdf:type ?tbClass .
                FILTER (?tbClass != vwm:ToestandsBeschrijving)
                FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
            }
            ORDER BY ?tb
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable $e) {
            logger()->warning('Kon actieve TB-rows voor cascade niet uit GraphDB lezen', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $tbUri = $row['tb'] ?? null;
            if (! is_string($tbUri) || $tbUri === '') {
                continue;
            }
            $result[] = [
                'tb_uri' => $tbUri,
                'tb_class' => is_string($row['tbClass'] ?? null) ? $row['tbClass'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $tbUris
     * @return array<string, int>
     */
    private function fetchTbIdsByUris(array $tbUris): array
    {
        $uris = array_values(array_unique(array_filter($tbUris, fn ($uri) => is_string($uri) && $uri !== '')));
        if (empty($uris)) {
            return [];
        }

        $rows = DB::table('toestands_beschrijvingen')
            ->whereIn('rdf_uri', $uris)
            ->get(['id', 'rdf_uri']);

        $result = [];
        foreach ($rows as $row) {
            if (is_string($row->rdf_uri) && $row->rdf_uri !== '') {
                $result[$row->rdf_uri] = (int) $row->id;
            }
        }

        return $result;
    }

    private function isClassDeleteAllowed(int $transactieSoortId, string $tbClass): bool
    {
        if ($tbClass === '') {
            return false;
        }
        $allowed = $this->fetchAllowedSjabloonCrudByTbClass($transactieSoortId);
        return $this->hasCrud($allowed[$tbClass] ?? null, 'D');
    }
}
