<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMutatieRequest;
use App\Services\GoicDisplayService;
use App\Services\GoicFollowAction;
use App\Services\GoicUnfollowAction;
use App\Services\StoreMutationAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MutatieController extends Controller
{
    protected GoicDisplayService $goicDisplayService;

    protected GoicFollowAction $goicFollowAction;

    protected GoicUnfollowAction $goicUnfollowAction;

    protected StoreMutationAction $storeMutationAction;

    public function __construct(
        GoicDisplayService $goicDisplayService,
        GoicFollowAction $goicFollowAction,
        GoicUnfollowAction $goicUnfollowAction,
        StoreMutationAction $storeMutationAction,
    ) {
        $this->goicDisplayService = $goicDisplayService;
        $this->goicFollowAction = $goicFollowAction;
        $this->goicUnfollowAction = $goicUnfollowAction;
        $this->storeMutationAction = $storeMutationAction;
    }

    /**
     * Slaat de formulierdata op in zowel SQLite (audit) als GraphDB (triples).
     */
    public function storeMutatie(StoreMutatieRequest $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return $this->jsonError('Niet geauthenticeerd.', 401);
        }

        return $this->actionResponse($this->storeMutationAction->execute($request, $userId));
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
            return $this->jsonError('Niet geauthenticeerd.', 401);
        }

        $validated = $request->validate([
            'case_id' => 'required|integer',
        ]);

        $result = $this->goicFollowAction->execute($request->all(), (int) $validated['case_id'], $userId);
        if (isset($result['log'])) {
            $this->logFollowWarning(
                $result['log']['reason'],
                $result['log']['case_id'],
                $result['log']['user_id'],
                $result['log']['bron_goic_uri'] ?? null,
            );
        }

        return $this->actionResponse($result);
    }

    public function ontvolgGoic(Request $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return $this->jsonError('Niet geauthenticeerd.', 401);
        }

        $validated = $request->validate([
            'case_id' => 'required|integer',
        ]);

        $result = $this->goicUnfollowAction->execute($request->all(), (int) $validated['case_id'], $userId);

        return $this->actionResponse($result);
    }

    /**
     * Resolveer leesbare labels voor GOIC-URI's (ook buiten de actieve case),
     * zodat verwijzingen zoals heeftVoertuig het kenteken kunnen tonen.
     */
    public function resolveGoicDisplays(Request $request)
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return $this->jsonError('Niet geauthenticeerd.', 401);
        }

        $validated = $request->validate([
            'uris' => 'required|array|min:1',
            'uris.*' => 'required|string',
        ]);

        return response()->json([
            'labels' => $this->goicDisplayService->resolveLabels($validated['uris'], $userId),
        ]);
    }

    private function jsonError(string $error, int $status = 422, ?string $reason = null): JsonResponse
    {
        $payload = ['error' => $error];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array{status:int,payload:array<string,mixed>,options?:int}  $result
     */
    private function actionResponse(array $result): JsonResponse
    {
        return response()->json($result['payload'], $result['status'], [], $result['options'] ?? 0);
    }

    private function logFollowWarning(string $reason, int $caseId, int $userId, mixed $bronGoicUri = null): void
    {
        $context = [
            'case_id' => $caseId,
            'user_id' => $userId,
        ];

        if ($bronGoicUri !== null) {
            $context['bron_goic_uri'] = $bronGoicUri;
        }

        logger()->warning("volgGoic 422: {$reason}", $context);
    }
}
