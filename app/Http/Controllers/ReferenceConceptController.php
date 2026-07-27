<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReferenceConceptChildrenRequest;
use App\Http\Requests\ReferenceConceptLabelsRequest;
use App\Services\ReferenceConceptService;
use Illuminate\Http\JsonResponse;

class ReferenceConceptController extends Controller
{
    public function __construct(private readonly ReferenceConceptService $referenceConceptService) {}

    public function children(ReferenceConceptChildrenRequest $request): JsonResponse
    {
        return response()->json(['concepten' => $this->referenceConceptService->children($request->validated('parent'))]);
    }

    public function labels(ReferenceConceptLabelsRequest $request): JsonResponse
    {
        return response()->json(['labels' => $this->referenceConceptService->labels($request->validated('uris'))]);
    }
}
