<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\MapMarkersRequest;
use App\Services\PublicPortalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicPortalController extends Controller
{
    public function __construct(
        private readonly PublicPortalService $portalService,
    ) {}

    public function referenceData(): JsonResponse
    {
        return ApiResponse::success($this->portalService->getReferenceData());
    }

    public function mapMarkers(MapMarkersRequest $request): JsonResponse
    {
        return ApiResponse::success($this->portalService->getMapMarkers($request->validated()));
    }
}
