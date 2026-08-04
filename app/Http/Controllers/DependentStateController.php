<?php

namespace App\Http\Controllers;

use App\Http\Requests\FollowDependentStateRequest;
use App\Services\FollowDependentStateService;
use Illuminate\Http\JsonResponse;

class DependentStateController extends Controller
{
    public function __construct(private readonly FollowDependentStateService $followDependentStateService) {}

    public function store(FollowDependentStateRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        $result = $this->followDependentStateService->follow($request->validated(), $userId);

        return response()->json($result['payload'], $result['status']);
    }
}
