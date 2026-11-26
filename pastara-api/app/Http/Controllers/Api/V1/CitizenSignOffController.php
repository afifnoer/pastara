<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Citizen\ApproveSignOffRequest;
use App\Http\Requests\Citizen\RejectSignOffRequest;
use App\Http\Requests\Citizen\StoreRatingRequest;
use App\Http\Resources\CitizenReportResource;
use App\Models\Report;
use App\Services\CitizenSignOffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CitizenSignOffController extends Controller
{
    public function __construct(
        private readonly CitizenSignOffService $signOffService,
    ) {}

    public function approve(ApproveSignOffRequest $request, Report $report): JsonResponse
    {
        $updated = $this->signOffService->approveSignOff(
            report: $report,
            actor: $request->user('sanctum'),
            secretToken: $request->validated('secret_token'),
        );

        return ApiResponse::success(new CitizenReportResource($updated));
    }

    public function reject(RejectSignOffRequest $request, Report $report): JsonResponse
    {
        $validated = $request->validated();
        $photos = array_values($request->file('photos', []));

        $updated = $this->signOffService->rejectSignOff(
            report: $report,
            actor: $request->user('sanctum'),
            secretToken: $validated['secret_token'] ?? null,
            reason: (string) $validated['reason'],
            photos: $photos,
        );

        return ApiResponse::success(new CitizenReportResource($updated));
    }

    public function rate(StoreRatingRequest $request, Report $report): JsonResponse
    {
        $validated = $request->validated();

        $rating = $this->signOffService->storeRating(
            report: $report,
            actor: $request->user('sanctum'),
            secretToken: $validated['secret_token'] ?? null,
            speedScore: (int) $validated['speed_score'],
            physicalQualityScore: (int) $validated['physical_quality_score'],
            comment: $validated['comment'] ?? null,
        );

        return ApiResponse::success($rating, status: 201);
    }
}
