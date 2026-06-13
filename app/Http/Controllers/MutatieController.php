<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMutatieRequest;
use App\Services\GoicFollowService;
use App\Services\GraphService;
use App\Services\MutationTargetResolver;
use App\Services\ObjectMutationCommitService;
use App\Services\ObjectMutationPreparationService;
use App\Services\RoleMutationService;
use App\Services\SjabloonMetadataService;
use App\Services\StateDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MutatieController extends Controller
{
    protected GraphService $graphService;

    protected SjabloonMetadataService $metadataService;

    protected MutationTargetResolver $mutationTargetResolver;

    protected RoleMutationService $roleMutationService;

    protected StateDeletionService $stateDeletionService;

    protected ObjectMutationCommitService $objectMutationCommitService;

    protected ObjectMutationPreparationService $objectMutationPreparationService;

    protected GoicFollowService $goicFollowService;

    public function __construct(
        GraphService $graphService,
        SjabloonMetadataService $metadataService,
        MutationTargetResolver $mutationTargetResolver,
        ObjectMutationCommitService $objectMutationCommitService,
        ObjectMutationPreparationService $objectMutationPreparationService,
        GoicFollowService $goicFollowService,
        RoleMutationService $roleMutationService,
        StateDeletionService $stateDeletionService,
    ) {
        $this->graphService = $graphService;
        $this->metadataService = $metadataService;
        $this->mutationTargetResolver = $mutationTargetResolver;
        $this->objectMutationCommitService = $objectMutationCommitService;
        $this->objectMutationPreparationService = $objectMutationPreparationService;
        $this->goicFollowService = $goicFollowService;
        $this->roleMutationService = $roleMutationService;
        $this->stateDeletionService = $stateDeletionService;
    }

    /**
     * Slaat de formulierdata op in zowel SQLite (audit) als GraphDB (triples).
     */
    public function storeMutatie(StoreMutatieRequest $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        $base = $request->base();
        $mode = $request->mode();

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
            return $this->stateDeletionService->delete($request, $base, (int) $dossier->id, $userId, $roleShapeRules);
        }

        $rolTypesByKey = $this->metadataService->fetchRolTypesByKey();
        $allowedRoleConfiguration = $this->roleMutationService->fetchAllowedRoleConfiguration((int) $base['transactie_soort_id']);
        $allowedRoleSelectors = $allowedRoleConfiguration['allowed_selectors'];
        $roleCrudBySelector = $allowedRoleConfiguration['crud_by_selector'];
        $enforceAllowedRole = $allowedRoleConfiguration['enforce_allowed'];

        $objects = $request->normalizedObjects();
        $rolesInput = $request->input('roles', []);

        $tbClasses = array_values(array_filter(array_unique(array_map(function ($object) {
            return $object['sjabloon_uri'] ?? null;
        }, $objects))));
        $mutationTargetMeta = null;
        if ($mode === 'mutate') {
            $mutationTargetMeta = $this->mutationTargetResolver->resolveMutationTargetMeta(
                $request->mutationTarget() ?? [],
                (int) $dossier->id
            );

            if (! $mutationTargetMeta) {
                return response()->json(['error' => 'Mutatiedoel niet gevonden of ongeldig.'], 422);
            }
        }
        $describedClassByTbClass = $this->metadataService->fetchDescribedClassByTbClasses($tbClasses);
        $tbClassCapabilities = $this->metadataService->fetchTbClassCapabilitiesByTbClasses($tbClasses);
        $allowedSjabloonCrud = $this->roleMutationService->fetchAllowedSjabloonCrudByTbClass((int) $base['transactie_soort_id']);

        $prepared = $this->objectMutationPreparationService->prepare(
            $objects,
            $mode,
            $mutationTargetMeta,
            $describedClassByTbClass,
            $tbClassCapabilities,
            $allowedSjabloonCrud
        );
        $objects = $prepared['objects'];
        if (is_string($prepared['error'])) {
            return response()->json(['error' => $prepared['error']], 422);
        }

        $goicTargetClassMap = $this->mutationTargetResolver->getGoicTargetClassMapForCase($base['case_id']);
        $classHierarchy = $this->metadataService->fetchSubclassClosureMap();
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
            $isToestandsWeergave = $this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbClassCapabilities, 'is_state_projection');
            $isBeschrijving = $this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbClassCapabilities, 'is_beschrijving');
            $candidateGoicIds = $this->mutationTargetResolver->resolveGoicIdsForTargetClass($targetClass, $goicIdsByClass, $classHierarchy);

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

                if (! $this->mutationTargetResolver->isClassAssignable($targetClass, $existingClass, $classHierarchy)) {
                    return response()->json([
                        'error' => "Geselecteerd object heeft class {$existingClass}, verwacht {$targetClass}.",
                    ], 422);
                }

                if (! $isToestandsWeergave && ! $attachToExisting) {
                    return response()->json([
                        'error' => "Bestaand object koppelen is niet toegestaan voor sjabloon {$tbClass}.",
                    ], 422);
                }

                if ($isBeschrijving) {
                    $existingGoicUri = $goicUriById[$existingGoicId] ?? null;
                    if (! is_string($existingGoicUri) || $existingGoicUri === '') {
                        return response()->json([
                            'error' => 'Kon bestaand GOIC niet resolven.',
                        ], 422);
                    }

                    $attachCheck = $this->mutationTargetResolver->evaluateBeschrijvingAttachEligibility((string) $existingGoicUri, $targetClass);
                    if (! $attachCheck['has_signalement']) {
                        return response()->json([
                            'error' => 'Beschrijving toevoegen kan alleen op een object met actief signalement.',
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
                if ($isBeschrijving) {
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

        return $this->objectMutationCommitService->commit(
            $base,
            $objects,
            $roleShapeRules,
            $rolTypesByKey,
            $allowedRoleSelectors,
            $roleCrudBySelector,
            $enforceAllowedRole,
            $dossier,
            $userId,
            $mode,
            $mutationTargetMeta,
            $goicTargetClassMap,
            $tbClasses,
            is_array($rolesInput) ? $rolesInput : []
        );
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

        $sourceMeta = $this->goicFollowService->fetchSourceGoicMeta($bronGoicUri);
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

        $sourceTargetClass = $sourceMeta['target_class'] ?? null;
        if (! is_string($sourceTargetClass) || $sourceTargetClass === '') {
            logger()->warning('volgGoic 422: bron doelclass ontbreekt', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);

            return response()->json([
                'error' => 'Kon geen doelclass vinden voor bron GOIC.',
                'reason' => 'source_target_class_missing',
            ], 422);
        }

        $alreadyFollowed = $this->goicFollowService->findExistingFollowedGoicForCase((int) $targetCase->id, $bronGoicUri);
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

        $result = $this->goicFollowService->follow(
            $targetCase,
            $targetDossier,
            (int) $transactieSoortId,
            $bronGoicUri,
            $goUri,
            $sourceTargetClass,
            $userId
        );

        return response()->json([
            'message' => 'GOIC wordt nu gevolgd vanuit deze case.',
            'goic_id' => $result['goic_id'],
            'goic_uri' => $result['goic_uri'],
            'association_uri' => $result['association_uri'],
            'target_class' => $result['target_class'],
        ]);
    }

    public function ontvolgGoic(Request $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        $validated = $request->validate([
            'case_id' => 'required|integer',
            'association_uri' => 'required|string',
        ]);

        $targetCase = DB::table('cases')
            ->where('id', (int) $validated['case_id'])
            ->where('user_id', $userId)
            ->first(['id', 'case_soort_id']);

        if (! $targetCase) {
            return response()->json(['error' => 'Geen toegang tot deze case.'], 403);
        }

        $associationUri = trim((string) $validated['association_uri']);
        if ($associationUri === '' || ! preg_match('/^https?:\/\/[^\s<>"\']+$/', $associationUri)) {
            return response()->json([
                'error' => 'Ongeldige association URI.',
                'reason' => 'invalid_uri_format',
            ], 422);
        }

        $transactieSoortId = DB::table('case_soort_transactie')
            ->where('case_soort_id', (int) $targetCase->case_soort_id)
            ->orderBy('volgorde')
            ->value('transactie_soort_id');

        if (! $transactieSoortId) {
            $transactieSoortId = DB::table('transactie_soorten')->orderBy('id')->value('id');
        }

        if (! $transactieSoortId) {
            return response()->json([
                'error' => 'Geen transactie-soort beschikbaar.',
                'reason' => 'transactie_soort_missing',
            ], 422);
        }

        $result = $this->goicFollowService->unfollow(
            (int) $targetCase->id,
            (int) $transactieSoortId,
            $associationUri,
            $userId
        );

        if (! $result) {
            return response()->json([
                'error' => 'Actieve volgrelatie niet gevonden.',
                'reason' => 'active_association_missing',
            ], 422);
        }

        return response()->json([
            'message' => 'Registratie wordt niet meer gevolgd.',
            'association_uri' => $result['association_uri'],
            'goic_id' => $result['goic_id'],
            'goic_uri' => $result['goic_uri'],
            'target_goic_uri' => $result['target_goic_uri'],
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
}
