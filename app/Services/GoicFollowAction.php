<?php

namespace App\Services;

use Illuminate\Http\Request;

class GoicFollowAction
{
    public function __construct(
        private readonly GoicFollowInputService $goicFollowInputService,
        private readonly CaseMutationContextService $caseMutationContextService,
        private readonly GoicFollowService $goicFollowService,
    ) {}

    /**
     * @return array{status:int,payload:array<string,mixed>,log?:array{reason:string,case_id:int,user_id:int,bron_goic_uri?:mixed}}
     */
    public function execute(Request $request, int $caseId, int $userId): array
    {
        $sourceInput = $this->goicFollowInputService->resolveSourceGoicUri($request, $request->all());
        if (isset($sourceInput['reason'])) {
            return $this->error($sourceInput['error'], $sourceInput['reason'], 422, [
                'reason' => $sourceInput['reason'],
                'case_id' => $caseId,
                'user_id' => $userId,
                'bron_goic_uri' => $request->input('bron_goic_uri'),
            ]);
        }

        $context = $this->caseMutationContextService->resolveFollowContext($caseId, $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return $this->error('Geen toegang tot deze case.', null, 403);
        }

        $targetCase = $context['case'];
        if (($context['reason'] ?? null) === 'dossier_missing') {
            return $this->error('Geen dossier gevonden voor deze case.', 'target_case_has_no_dossier', 422, [
                'reason' => 'target_case_has_no_dossier',
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
            ]);
        }

        $targetDossier = $context['dossier'];
        $bronGoicUri = $sourceInput['uri'];
        $sourceForFollow = $this->goicFollowService->resolveSourceForFollow((int) $targetCase->id, $bronGoicUri);

        if (($sourceForFollow['reason'] ?? null) === 'source_meta_missing') {
            return $this->error('Bron GOIC niet gevonden in GraphDB.', 'source_meta_missing', 422, [
                'reason' => 'source_meta_missing',
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);
        }

        if (($sourceForFollow['reason'] ?? null) === 'source_go_missing') {
            return $this->error('Kon geen GO vinden voor bron GOIC.', 'source_go_missing', 422, [
                'reason' => 'source_go_missing',
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);
        }

        if (($sourceForFollow['reason'] ?? null) === 'source_target_class_missing') {
            return $this->error('Kon geen doelclass vinden voor bron GOIC.', 'source_target_class_missing', 422, [
                'reason' => 'source_target_class_missing',
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
                'bron_goic_uri' => $bronGoicUri,
            ]);
        }

        $alreadyFollowed = $sourceForFollow['already_followed'];
        if ($alreadyFollowed) {
            return [
                'status' => 200,
                'payload' => [
                    'message' => 'Deze case volgt deze GOIC al.',
                    'goic_id' => (int) $alreadyFollowed['goic_id'],
                    'goic_uri' => $alreadyFollowed['goic_uri'],
                    'already_exists' => true,
                ],
            ];
        }

        if (($context['reason'] ?? null) === 'transactie_soort_missing') {
            return $this->error('Geen transactie-soort beschikbaar.', 'transactie_soort_missing', 422, [
                'reason' => 'transactie_soort_missing',
                'case_id' => (int) $targetCase->id,
                'user_id' => $userId,
            ]);
        }

        $result = $this->goicFollowService->follow(
            $targetCase,
            $targetDossier,
            (int) $context['transactie_soort_id'],
            $bronGoicUri,
            $sourceForFollow['go_uri'],
            $sourceForFollow['target_class'],
            $userId
        );

        return [
            'status' => 200,
            'payload' => [
                'message' => 'GOIC wordt nu gevolgd vanuit deze case.',
                'goic_id' => $result['goic_id'],
                'goic_uri' => $result['goic_uri'],
                'association_uri' => $result['association_uri'],
                'target_class' => $result['target_class'],
            ],
        ];
    }

    /**
     * @param  array{reason:string,case_id:int,user_id:int,bron_goic_uri?:mixed}|null  $log
     * @return array{status:int,payload:array<string,mixed>,log?:array{reason:string,case_id:int,user_id:int,bron_goic_uri?:mixed}}
     */
    private function error(string $error, ?string $reason = null, int $status = 422, ?array $log = null): array
    {
        $payload = ['error' => $error];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        $result = [
            'status' => $status,
            'payload' => $payload,
        ];

        if ($log !== null) {
            $result['log'] = $log;
        }

        return $result;
    }
}
