<?php

namespace App\Http\Middleware;

use App\Services\BeheerAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBeheerAccess
{
    public function __construct(private readonly BeheerAccessService $beheerAccessService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->id;
        if (! is_int($userId)) {
            abort(403);
        }

        if (! $this->beheerAccessService->userHasBeheerAccess($userId)) {
            abort(403);
        }

        return $next($request);
    }
}
