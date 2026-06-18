<?php

namespace App\Services;

use App\Http\Requests\StoreMutatieRequest;
use Illuminate\Http\JsonResponse;

class StoreMutationAction
{
    public function __construct(
        private readonly SjabloonMetadataService $metadataService,
        private readonly MutationTargetResolver $mutationTargetResolver,
        private readonly ObjectMutationCommitService $objectMutationCommitService,
        private readonly ObjectMutationPreparationService $objectMutationPreparationService,
        private readonly ObjectMutationTargetService $objectMutationTargetService,
        private readonly CaseMutationContextService $caseMutationContextService,
        private readonly RoleMutationService $roleMutationService,
        private readonly StateDeletionService $stateDeletionService,
    ) {}

    public function execute(StoreMutatieRequest $request, int $userId): JsonResponse
    {
        $base = $request->base();
        $mode = $request->mode();

        $context = $this->caseMutationContextService->resolveStoreContext((int) $base['case_id'], $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return $this->jsonError('Geen toegang tot deze case.', 403);
        }
        if (($context['reason'] ?? null) === 'dossier_missing') {
            return $this->jsonError('Geen dossier gevonden voor deze case');
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
                return $this->jsonError('Mutatiedoel niet gevonden of ongeldig.');
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
            return $this->jsonError($prepared['error']);
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
            return $this->jsonError($targetResolution['error']);
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

    private function jsonError(string $error, int $status = 422): JsonResponse
    {
        return response()->json(['error' => $error], $status);
    }
}
