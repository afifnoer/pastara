<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Officer\OfficerTasksRequest;
use App\Http\Requests\Officer\StoreProgressRequest;
use App\Http\Requests\Officer\SubmitEvidenceRequest;
use App\Http\Resources\UnitReportResource;
use App\Models\Report;
use App\Models\User;
use App\Services\OfficerTaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class OfficerTaskController extends Controller
{
    public function __construct(
        private readonly OfficerTaskService $officerService,
    ) {}

    public function index(OfficerTasksRequest $request): JsonResponse
    {
        /** @var User $officer */
        $officer = $request->user();
        $paginator = $this->officerService->listTasks($officer, $request->validated());

        return ApiResponse::success(
            UnitReportResource::collection($paginator->getCollection())->resolve($request),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function storeProgress(StoreProgressRequest $request, Report $report): JsonResponse
    {
        /** @var User $officer */
        $officer = $request->user();
        $photos = array_values($request->file('photos', []));

        $updated = $this->officerService->storeProgress(
            $report,
            $officer,
            (string) $request->validated('note'),
            $photos,
        );

        return ApiResponse::success(new UnitReportResource($updated), status: 201);
    }

    public function submitEvidence(SubmitEvidenceRequest $request, Report $report): JsonResponse
    {
        /** @var User $officer */
        $officer = $request->user();
        $validated = $request->validated();
        $photos = array_values($request->file('photos', []));

        $updated = $this->officerService->submitEvidence(
            $report,
            $officer,
            (string) $validated['action_note'],
            $photos,
            isset($validated['device_latitude']) ? (float) $validated['device_latitude'] : null,
            isset($validated['device_longitude']) ? (float) $validated['device_longitude'] : null,
        );

        return ApiResponse::success(new UnitReportResource($updated));
    }
}
