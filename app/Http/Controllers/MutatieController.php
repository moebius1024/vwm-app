<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMutatieRequest;
use App\Services\CaseMutationContextService;
use App\Services\GoicDisplayService;
use App\Services\GoicFollowInputService;
use App\Services\GoicFollowService;
use App\Services\MutationTargetResolver;
use App\Services\ObjectMutationCommitService;
use App\Services\ObjectMutationPreparationService;
use App\Services\ObjectMutationTargetService;
use App\Services\RoleMutationService;
use App\Services\SjabloonMetadataService;
use App\Services\StateDeletionService;
use Illuminate\Http\Request;

class MutatieController extends Controller
{
    protected SjabloonMetadataService $metadataService;

    protected MutationTargetResolver $mutationTargetResolver;

    protected RoleMutationService $roleMutationService;

    protected StateDeletionService $stateDeletionService;

    protected ObjectMutationCommitService $objectMutationCommitService;

    protected ObjectMutationPreparationService $objectMutationPreparationService;

    protected ObjectMutationTargetService $objectMutationTargetService;

    protected GoicFollowService $goicFollowService;

    protected GoicDisplayService $goicDisplayService;

    protected CaseMutationContextService $caseMutationContextService;

    protected GoicFollowInputService $goicFollowInputService;

    public function __construct(
        SjabloonMetadataService $metadataService,
        MutationTargetResolver $mutationTargetResolver,
        ObjectMutationCommitService $objectMutationCommitService,
        ObjectMutationPreparationService $objectMutationPreparationService,
        ObjectMutationTargetService $objectMutationTargetService,
        GoicFollowService $goicFollowService,
        GoicDisplayService $goicDisplayService,
        CaseMutationContextService $caseMutationContextService,
        GoicFollowInputService $goicFollowInputService,
        RoleMutationService $roleMutationService,
        StateDeletionService $stateDeletionService,
    ) {
        $this->metadataService = $metadataService;
        $this->mutationTargetResolver = $mutationTargetResolver;
        $this->objectMutationCommitService = $objectMutationCommitService;
        $this->objectMutationPreparationService = $objectMutationPreparationService;
        $this->objectMutationTargetService = $objectMutationTargetService;
        $this->goicFollowService = $goicFollowService;
        $this->goicDisplayService = $goicDisplayService;
        $this->caseMutationContextService = $caseMutationContextService;
        $this->goicFollowInputService = $goicFollowInputService;
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

        $context = $this->caseMutationContextService->resolveStoreContext((int) $base['case_id'], $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return response()->json(['error' => 'Geen toegang tot deze case.'], 403);
        }
        if (($context['reason'] ?? null) === 'dossier_missing') {
            return response()->json(['error' => 'Geen dossier gevonden voor deze case'], 422);
        }
        $dossier = $context['dossier'];

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

        $targetResolution = $this->objectMutationTargetService->resolve(
            $objects,
            $mode,
            $mutationTargetMeta,
            (int) $base['case_id'],
            $tbClassCapabilities
        );
        $objects = $targetResolution['objects'];
        $goicTargetClassMap = $targetResolution['goic_target_class_map'];
        if (is_string($targetResolution['error'])) {
            return response()->json(['error' => $targetResolution['error']], 422);
        }

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
        ]);

        $sourceInput = $this->goicFollowInputService->resolveSourceGoicUri($request, $request->all());
        if (isset($sourceInput['reason'])) {
            logger()->warning("volgGoic 422: {$sourceInput['reason']}", [
                'case_id' => $validated['case_id'],
                'user_id' => $userId,
                'bron_goic_uri' => $request->input('bron_goic_uri'),
            ]);

            return response()->json([
                'error' => $sourceInput['error'],
                'reason' => $sourceInput['reason'],
            ], 422);
        }

        $context = $this->caseMutationContextService->resolveFollowContext((int) $validated['case_id'], $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return response()->json(['error' => 'Geen toegang tot deze case.'], 403);
        }
        $targetCase = $context['case'];

        if (($context['reason'] ?? null) === 'dossier_missing') {
            logger()->warning('volgGoic 422: geen dossier', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
            ]);

            return response()->json([
                'error' => 'Geen dossier gevonden voor deze case.',
                'reason' => 'target_case_has_no_dossier',
            ], 422);
        }
        $targetDossier = $context['dossier'];

        $bronGoicUri = $sourceInput['uri'];

        $sourceForFollow = $this->goicFollowService->resolveSourceForFollow((int) $targetCase->id, $bronGoicUri);
        if (($sourceForFollow['reason'] ?? null) === 'source_meta_missing') {
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
        if (($sourceForFollow['reason'] ?? null) === 'source_go_missing') {
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
        if (($sourceForFollow['reason'] ?? null) === 'source_target_class_missing') {
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

        $goUri = $sourceForFollow['go_uri'];
        $sourceTargetClass = $sourceForFollow['target_class'];
        $alreadyFollowed = $sourceForFollow['already_followed'];
        if ($alreadyFollowed) {
            return response()->json([
                'message' => 'Deze case volgt deze GOIC al.',
                'goic_id' => (int) $alreadyFollowed['goic_id'],
                'goic_uri' => $alreadyFollowed['goic_uri'],
                'already_exists' => true,
            ], 200);
        }

        if (($context['reason'] ?? null) === 'transactie_soort_missing') {
            logger()->warning('volgGoic 422: geen transactie soort', [
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
            ]);

            return response()->json([
                'error' => 'Geen transactie-soort beschikbaar.',
                'reason' => 'transactie_soort_missing',
            ], 422);
        }
        $transactieSoortId = $context['transactie_soort_id'];

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
        ]);

        $context = $this->caseMutationContextService->resolveUnfollowContext((int) $validated['case_id'], $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return response()->json(['error' => 'Geen toegang tot deze case.'], 403);
        }
        $targetCase = $context['case'];

        $associationInput = $this->goicFollowInputService->resolveAssociationUri($request->all());
        if (isset($associationInput['reason'])) {
            return response()->json([
                'error' => $associationInput['error'],
                'reason' => $associationInput['reason'],
            ], 422);
        }
        $associationUri = $associationInput['uri'];

        if (($context['reason'] ?? null) === 'transactie_soort_missing') {
            return response()->json([
                'error' => 'Geen transactie-soort beschikbaar.',
                'reason' => 'transactie_soort_missing',
            ], 422);
        }
        $transactieSoortId = $context['transactie_soort_id'];

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

        return response()->json([
            'labels' => $this->goicDisplayService->resolveLabels($validated['uris'], $userId),
        ]);
    }
}
