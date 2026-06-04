<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
}
