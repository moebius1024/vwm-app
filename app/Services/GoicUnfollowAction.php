<?php

namespace App\Services;

class GoicUnfollowAction
{
    public function __construct(
        private readonly GoicFollowInputService $goicFollowInputService,
        private readonly CaseMutationContextService $caseMutationContextService,
        private readonly GoicFollowService $goicFollowService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(array $input, int $caseId, int $userId): array
    {
        $context = $this->caseMutationContextService->resolveUnfollowContext($caseId, $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return $this->error('Geen toegang tot deze case.', null, 403);
        }

        $targetCase = $context['case'];
        $associationInput = $this->goicFollowInputService->resolveAssociationUri($input);
        if (isset($associationInput['reason'])) {
            return $this->error($associationInput['error'], $associationInput['reason']);
        }

        if (($context['reason'] ?? null) === 'transactie_soort_missing') {
            return $this->error('Geen transactie-soort beschikbaar.', 'transactie_soort_missing');
        }

        $result = $this->goicFollowService->unfollow(
            (int) $targetCase->id,
            (int) $context['transactie_soort_id'],
            $associationInput['uri'],
            $userId
        );

        if (! $result) {
            return $this->error('Actieve volgrelatie niet gevonden.', 'active_association_missing');
        }

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Registratie wordt niet meer gevolgd.',
                'association_uri' => $result['association_uri'],
                'goic_id' => $result['goic_id'],
                'goic_uri' => $result['goic_uri'],
                'target_goic_uri' => $result['target_goic_uri'],
            ],
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function error(string $error, ?string $reason = null, int $status = 422): array
    {
        $payload = ['error' => $error];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return [
            'status' => $status,
            'payload' => $payload,
        ];
    }
}
