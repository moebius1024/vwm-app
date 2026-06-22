<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleMutationService
{
    public function __construct(private readonly SjabloonMetadataService $metadataService) {}

    /**
     * @return array{allowed_selectors:array<int,string>,crud_by_selector:array<string,string>,enforce_allowed:bool}
     */
    public function fetchAllowedRoleConfiguration(int $transactieSoortId): array
    {
        $allowedRoleRows = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'rol')
            ->orderBy('volgorde')
            ->get(['sjabloon_uri', 'crud_flags'])
            ->all();

        $allowedSelectors = array_values(array_filter(array_map(fn ($row) => $row->sjabloon_uri ?? null, $allowedRoleRows)));
        $crudBySelector = [];
        foreach ($allowedRoleRows as $row) {
            if (! is_string($row->sjabloon_uri ?? null) || $row->sjabloon_uri === '') {
                continue;
            }

            $crudBySelector[$row->sjabloon_uri] = strtoupper((string) ($row->crud_flags ?? 'CRD'));
        }

        return [
            'allowed_selectors' => $allowedSelectors,
            'crud_by_selector' => $crudBySelector,
            'enforce_allowed' => ! empty($allowedSelectors),
        ];
    }

    public function hasCrud(?string $flags, string $required): bool
    {
        return str_contains(strtoupper((string) ($flags ?? 'CRUD')), strtoupper($required));
    }

    /**
     * @return array<string,string>
     */
    public function fetchAllowedSjabloonCrudByTbClass(int $transactieSoortId): array
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

    public function isAllowedRoleSelection(?string $roleType, ?string $roleSelector, array $allowedSelectors, array $roleShapeRules): bool
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

    public function isRoleCreateAllowed(?string $roleType, ?string $roleSelector, array $roleCrudBySelector, array $roleShapeRules): bool
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

    public function isRoleDeleteAllowed(int $transactieSoortId, string $roleTbClass, array $roleShapeRules): bool
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

    public function isRoleTbClass(string $tbClass, array $roleShapeRules): bool
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
     * @return array<int,array<string,mixed>>
     */
    public function normalizeRoleItems(array $roles, array $rolTypesByKey): array
    {
        $roleItems = is_array($roles['items'] ?? null) ? $roles['items'] : [];

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

        return $roleItems;
    }

    /**
     * @return array<int,string>
     */
    public function collectRoleTbClasses(array $roleItems): array
    {
        return array_values(array_filter(array_map(function ($item) {
            return is_array($item) ? ($item['roleTbClass'] ?? null) : null;
        }, $roleItems)));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function buildClientMap(array $objectMeta): array
    {
        $clientMap = [];
        foreach ($objectMeta as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            if (! empty($meta['client_id'])) {
                $clientMap[$meta['client_id']] = $meta;
            }
        }

        return $clientMap;
    }

    /**
     * @return array<int,array{role_type:?string,role_tb_class:string,from_goic_id:int|string|null,from_goic_uri:string,to_goic_uri:string,van_property:string,naar_property:string}>
     */
    public function buildRoleMutationPlans(
        array $roleItems,
        array $rolTbMetaByClass,
        array $roleShapeRules,
        array $allowedRoleSelectors,
        array $roleCrudBySelector,
        bool $enforceAllowedRole,
        array $goicMetaById,
        array $clientMap,
        array $goicByClass
    ): array {
        $plans = [];

        foreach ($roleItems as $roleItem) {
            if (! is_array($roleItem)) {
                continue;
            }

            $roleType = $roleItem['roleType'] ?? null;
            $roleTbClass = $roleItem['roleTbClass'] ?? null;
            $fromId = $roleItem['fromId'] ?? null;
            $toId = $roleItem['toId'] ?? null;
            $fromGoicId = $roleItem['fromGoicId'] ?? null;
            $toGoicId = $roleItem['toGoicId'] ?? null;
            $toUri = $roleItem['toUri'] ?? null;
            $isAutoRole = (bool) ($roleItem['isAuto'] ?? false);

            if ((empty($roleType) && empty($roleTbClass)) || (empty($fromId) && empty($fromGoicId))) {
                if ($this->skipAutoRoleOrReject($isAutoRole, 'Rol kan niet worden verwerkt: roltype of bronobject ontbreekt.')) {
                    continue;
                }

                continue;
            }

            $roleMeta = $this->resolveRoleMeta($roleType, $roleTbClass, $rolTbMetaByClass, $roleShapeRules);
            if (! $roleMeta || empty($roleMeta['rolTbClass'])) {
                if ($this->skipAutoRoleOrReject($isAutoRole, 'Rol kan niet worden verwerkt: rolmetadata ontbreekt.')) {
                    continue;
                }

                continue;
            }

            $resolvedRoleType = $this->resolveRoleType($roleType, $roleTbClass, $roleShapeRules);
            if ($enforceAllowedRole && ! $this->isAllowedRoleSelection($resolvedRoleType, $roleTbClass, $allowedRoleSelectors, $roleShapeRules)) {
                if ($this->skipAutoRoleOrReject($isAutoRole, 'Deze rol is niet toegestaan binnen deze transactie.')) {
                    continue;
                }

                continue;
            }

            if (! $isAutoRole && ! $this->isRoleCreateAllowed($resolvedRoleType, $roleTbClass, $roleCrudBySelector, $roleShapeRules)) {
                $this->rejectRoleItem('Aanmaken van deze rol is niet toegestaan binnen deze transactie.');
            }

            $fromMeta = $this->resolveSourceGoicMeta($fromGoicId, $fromId, $goicMetaById, $clientMap);
            if (! $fromMeta) {
                if ($this->skipAutoRoleOrReject($isAutoRole, 'Rol kan niet worden verwerkt: bronobject niet gevonden.')) {
                    continue;
                }

                continue;
            }

            if (($fromMeta['target_class'] ?? null) !== $roleMeta['vanClass']) {
                $actualClass = (string) ($fromMeta['target_class'] ?? '');
                if ($this->skipAutoRoleOrReject($isAutoRole, "Rol kan niet worden verwerkt: bronobject heeft class {$actualClass}, verwacht {$roleMeta['vanClass']}.")) {
                    continue;
                }

                continue;
            }

            $targetGoics = $this->resolveTargetGoicUris($roleItem, $roleMeta, $goicMetaById, $clientMap, $goicByClass, $isAutoRole);
            if (empty($targetGoics)) {
                if ($this->skipAutoRoleOrReject($isAutoRole, 'Rol kan niet worden verwerkt: geen passend doelobject gevonden.')) {
                    continue;
                }

                continue;
            }

            foreach ($targetGoics as $toGoic) {
                $plans[] = [
                    'role_type' => is_string($resolvedRoleType) && $resolvedRoleType !== '' ? $resolvedRoleType : null,
                    'role_tb_class' => (string) $roleMeta['rolTbClass'],
                    'from_goic_id' => $fromMeta['goic_id'] ?? null,
                    'from_goic_uri' => (string) $fromMeta['goic_uri'],
                    'to_goic_uri' => (string) $toGoic,
                    'van_property' => (string) $roleMeta['vanProperty'],
                    'naar_property' => (string) $roleMeta['naarProperty'],
                ];
            }
        }

        return $plans;
    }

    /**
     * @return array{0:?string,1:?string}
     */
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

    private function resolveRoleMeta(mixed $roleType, mixed $roleTbClass, array $rolTbMetaByClass, array $roleShapeRules): ?array
    {
        if (! empty($roleTbClass) && is_string($roleTbClass)) {
            $roleMeta = $rolTbMetaByClass[$roleTbClass] ?? null;
            if (is_array($roleMeta)) {
                return $roleMeta;
            }
        }

        if (! empty($roleType) && is_string($roleType)) {
            $rule = $roleShapeRules[$roleType] ?? null;
            if (is_array($rule)) {
                return $this->roleMetaFromShapeRule($rule);
            }
        }

        if (! empty($roleTbClass) && is_string($roleTbClass)) {
            $rule = $this->metadataService->resolveRoleShapeRuleFromSelector($roleTbClass, $roleShapeRules);
            if (is_array($rule)) {
                return $this->roleMetaFromShapeRule($rule);
            }
        }

        return null;
    }

    private function resolveRoleType(mixed $roleType, mixed $roleTbClass, array $roleShapeRules): ?string
    {
        if (is_string($roleType) && $roleType !== '') {
            return $roleType;
        }

        if (! is_string($roleTbClass) || $roleTbClass === '') {
            return null;
        }

        $rule = $this->metadataService->resolveRoleShapeRuleFromSelector($roleTbClass, $roleShapeRules);
        $resolvedRoleType = $rule['rolType'] ?? null;

        return is_string($resolvedRoleType) && $resolvedRoleType !== '' ? $resolvedRoleType : null;
    }

    private function roleMetaFromShapeRule(array $rule): array
    {
        return [
            'rolTbClass' => $rule['rolTbClass'] ?? null,
            'vanClass' => $rule['vanClass'] ?? null,
            'naarClass' => $rule['naarClass'] ?? null,
            'vanProperty' => $rule['vanProperty'] ?? null,
            'naarProperty' => $rule['naarProperty'] ?? null,
        ];
    }

    private function resolveSourceGoicMeta(mixed $fromGoicId, mixed $fromId, array $goicMetaById, array $clientMap): ?array
    {
        if (! empty($fromGoicId) && ! empty($goicMetaById[$fromGoicId]) && is_array($goicMetaById[$fromGoicId])) {
            return $goicMetaById[$fromGoicId];
        }

        if (! empty($fromId) && ! empty($clientMap[$fromId]) && is_array($clientMap[$fromId])) {
            return $clientMap[$fromId];
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function resolveTargetGoicUris(
        array $roleItem,
        array $roleMeta,
        array $goicMetaById,
        array $clientMap,
        array $goicByClass,
        bool $isAutoRole
    ): array {
        $toUri = $roleItem['toUri'] ?? null;
        $toGoicId = $roleItem['toGoicId'] ?? null;
        $toId = $roleItem['toId'] ?? null;
        $targetClass = (string) ($roleMeta['naarClass'] ?? '');

        if (is_string($toUri) && $toUri !== '') {
            return [$toUri];
        }

        if (! empty($toGoicId)) {
            if (empty($goicMetaById[$toGoicId]) || ! is_array($goicMetaById[$toGoicId])) {
                if ($this->skipAutoRoleOrReject($isAutoRole, 'Rol kan niet worden verwerkt: doelobject niet gevonden.')) {
                    return [];
                }

                return [];
            }

            $toMeta = $goicMetaById[$toGoicId];
            if (($toMeta['target_class'] ?? null) === $targetClass) {
                return [(string) $toMeta['goic_uri']];
            }

            $actualClass = (string) ($toMeta['target_class'] ?? '');
            if ($this->skipAutoRoleOrReject($isAutoRole, "Rol kan niet worden verwerkt: doelobject heeft class {$actualClass}, verwacht {$targetClass}.")) {
                return [];
            }

            return [];
        }

        if (! empty($toId) && ! empty($clientMap[$toId]) && is_array($clientMap[$toId])) {
            $toMeta = $clientMap[$toId];
            if (($toMeta['target_class'] ?? null) === $targetClass) {
                return [(string) $toMeta['goic_uri']];
            }

            $actualClass = (string) ($toMeta['target_class'] ?? '');
            if ($this->skipAutoRoleOrReject($isAutoRole, "Rol kan niet worden verwerkt: doelobject heeft class {$actualClass}, verwacht {$targetClass}.")) {
                return [];
            }

            return [];
        }

        return array_values(array_filter($goicByClass[$targetClass] ?? []));
    }

    private function skipAutoRoleOrReject(bool $isAutoRole, string $message): bool
    {
        if ($isAutoRole) {
            return true;
        }

        $this->rejectRoleItem($message);
    }

    private function rejectRoleItem(string $message): never
    {
        throw ValidationException::withMessages([
            'roles.items' => [$message],
        ]);
    }
}
