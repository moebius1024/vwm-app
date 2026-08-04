<?php

namespace App\Http\Controllers;

use App\Http\Requests\LinkExistingIdentityRequest;
use App\Services\IdentityLinkService;
use Illuminate\Http\JsonResponse;

class IdentityLinkController extends Controller
{
    public function __construct(private readonly IdentityLinkService $identityLinkService) {}

    public function store(LinkExistingIdentityRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            return response()->json(['error' => 'Niet geauthenticeerd.'], 401);
        }

        $result = $this->identityLinkService->link($request->validated(), $userId);

        return response()->json($result['payload'], $result['status']);
    }
}
