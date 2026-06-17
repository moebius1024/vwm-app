<?php

namespace App\Services;

class CaseMutationContextService
{
    public function __construct(
        protected CaseAccessService $caseAccessService,
        protected CaseTransactionService $caseTransactionService,
    ) {}

    /**
     * @return array{case?: object, dossier?: object, reason?: string}
     */
    public function resolveStoreContext(int $caseId, int $userId): array
    {
        $case = $this->caseAccessService->findUserCase($caseId, $userId, ['id']);
        if (! $case) {
            return ['reason' => 'case_not_accessible'];
        }

        $dossier = $this->caseAccessService->findPrimaryDossierForCase($caseId);
        if (! $dossier) {
            return ['reason' => 'dossier_missing'];
        }

        return [
            'case' => $case,
            'dossier' => $dossier,
        ];
    }

    /**
     * @return array{case?: object, dossier?: object, transactie_soort_id?: int, reason?: string}
     */
    public function resolveFollowContext(int $caseId, int $userId): array
    {
        $case = $this->caseAccessService->findUserCase($caseId, $userId, ['id', 'case_soort_id']);
        if (! $case) {
            return ['reason' => 'case_not_accessible'];
        }

        $dossier = $this->caseAccessService->findPrimaryDossierForCase($caseId, ['id', 'rdf_uri']);
        if (! $dossier) {
            return [
                'case' => $case,
                'reason' => 'dossier_missing',
            ];
        }

        $transactieSoortId = $this->caseTransactionService->resolveDefaultTransactionTypeId((int) $case->case_soort_id);
        if (! $transactieSoortId) {
            return [
                'case' => $case,
                'dossier' => $dossier,
                'reason' => 'transactie_soort_missing',
            ];
        }

        return [
            'case' => $case,
            'dossier' => $dossier,
            'transactie_soort_id' => $transactieSoortId,
        ];
    }

    /**
     * @return array{case?: object, transactie_soort_id?: int, reason?: string}
     */
    public function resolveUnfollowContext(int $caseId, int $userId): array
    {
        $case = $this->caseAccessService->findUserCase($caseId, $userId, ['id', 'case_soort_id']);
        if (! $case) {
            return ['reason' => 'case_not_accessible'];
        }

        $transactieSoortId = $this->caseTransactionService->resolveDefaultTransactionTypeId((int) $case->case_soort_id);
        if (! $transactieSoortId) {
            return [
                'case' => $case,
                'reason' => 'transactie_soort_missing',
            ];
        }

        return [
            'case' => $case,
            'transactie_soort_id' => $transactieSoortId,
        ];
    }
}
