<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BrowseReportsRequest;
use App\Http\Requests\Public\MapMarkersRequest;
use App\Http\Requests\Public\ScorecardRequest;
use App\Http\Requests\Public\TrackReportRequest;
use App\Http\Resources\PublicReportCardResource;
use App\Http\Resources\PublicReportResource;
use App\Services\PublicPortalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

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

    public function reports(BrowseReportsRequest $request): JsonResponse
    {
        $paginator = $this->portalService->browseReports($request->validated());

        return ApiResponse::success(
            PublicReportCardResource::collection($paginator->getCollection())->resolve($request),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function show(string $ticketCode): JsonResponse
    {
        $report = $this->portalService->getReportForPublicDetail($ticketCode);
        Gate::authorize('viewPublic', $report);

        return ApiResponse::success(new PublicReportResource($report));
    }

    public function track(TrackReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->portalService->trackReport(
            $validated['ticket_code'],
            $validated['secret_token'] ?? null,
        );

        if ($result === null) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Tiket tidak ditemukan atau token tidak valid.',
                404
            );
        }

        if (isset($result['report'])) {
            $result['report'] = new PublicReportResource($result['report']);
        }

        return ApiResponse::success($result);
    }

    public function scorecard(ScorecardRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            $this->portalService->getPublicScorecard(
                isset($validated['month']) ? (int) $validated['month'] : null,
                isset($validated['year']) ? (int) $validated['year'] : null,
            )
        );
    }
}
