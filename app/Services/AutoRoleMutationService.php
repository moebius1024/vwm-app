<?php

namespace App\Services;

class AutoRoleMutationService
{
    public function __construct(
        private readonly SjabloonMetadataService $metadataService,
        private readonly RoleMutationService $roleMutationService,
        private readonly MutationTargetResolver $mutationTargetResolver,
    ) {}

    /**
     * @param  array<int, mixed>  $roleItems
     * @param  array<int, mixed>  $objects
     * @param  array<int, mixed>  $objectMeta
     * @param  array<string, array<int, string>>  $goicByClass
     * @param  array<string, array<string, mixed>>  $roleShapeRules
     * @return array<int, mixed>
     */
    public function appendAutoRoleItems(
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

    /**
     * @param  array<string, array<string, mixed>>  $roleShapeRules
     * @param  callable(string, string, string, string): array<int, string>  $activeRoleUriResolver
     * @return array<string, string>
     */
    public function collectRuleBasedInvalidationUris(
        string $deletedTbClass,
        string $sourceGoicUri,
        array $roleShapeRules,
        callable $activeRoleUriResolver
    ): array {
        $invalidationRules = $this->metadataService->fetchAutoRoleInvalidationRules();
        $extraRoleUris = [];

        foreach ($invalidationRules as $rule) {
            $triggerTbClass = (string) ($rule['triggerTbClass'] ?? '');
            $rolType = (string) ($rule['rolType'] ?? '');
            if ($triggerTbClass === '' || $rolType === '' || $triggerTbClass !== $deletedTbClass) {
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

            $uris = $activeRoleUriResolver($sourceGoicUri, $roleTbClass, $rolType, $fromProperty);
            foreach ($uris as $uri) {
                $extraRoleUris[$uri] = $roleTbClass;
            }
        }

        return $extraRoleUris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $remainingRows
     * @param  array<string, array<string, mixed>>  $roleShapeRules
     * @param  array<string, array<string, bool>>  $tbCapabilities
     * @return array<int, array<string, mixed>>
     */
    public function collectCascadeRowsWhenNoKernelRemains(array $remainingRows, array $roleShapeRules, array $tbCapabilities): array
    {
        $remainingKernel = array_values(array_filter($remainingRows, function (array $row) use ($roleShapeRules, $tbCapabilities): bool {
            $tbClass = (string) ($row['tb_class'] ?? '');
            if ($tbClass === '') {
                return false;
            }
            if ($this->roleMutationService->isRoleTbClass($tbClass, $roleShapeRules)) {
                return false;
            }
            if ($this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbCapabilities, 'is_state_projection')) {
                return false;
            }
            if (str_contains(strtolower($tbClass), 'dataobjectassociation')) {
                return false;
            }

            return $this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbCapabilities, 'is_signalement')
                || $this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbCapabilities, 'is_beschrijving');
        }));

        if (count($remainingKernel) > 0) {
            return [];
        }

        return array_values(array_filter($remainingRows, function (array $row): bool {
            $tbClass = (string) ($row['tb_class'] ?? '');
            if ($tbClass === '') {
                return false;
            }

            return ! str_contains(strtolower($tbClass), 'dataobjectassociation');
        }));
    }

    /**
     * @param  array<string, mixed>  $roleItem
     */
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
}
