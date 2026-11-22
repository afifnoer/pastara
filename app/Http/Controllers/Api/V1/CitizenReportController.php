<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Citizen\CitizenReportsRequest;
use App\Http\Requests\Citizen\NearbyReportRequest;
use App\Http\Requests\Citizen\StoreReportRequest;
use App\Http\Requests\Citizen\WatchReportRequest;
use App\Http\Resources\CitizenReportResource;
use App\Models\Report;
use App\Models\User;
use App\Services\CitizenReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CitizenReportController extends Controller
{
    public function __construct(
        private readonly CitizenReportService $citizenService,
    ) {}

    public function nearby(NearbyReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            $this->citizenService->findNearbyDuplicates(
                (int) $validated['category_id'],
                (float) $validated['latitude'],
                (float) $validated['longitude'],
            )
        );
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        /** @var User $citizen */
        $citizen = $request->user();
        $photos = array_values($request->file('photos', []));

        $data = $this->citizenService->createReport($citizen, $request->validated(), $photos);

        return ApiResponse::success($data, status: 201);
    }

    public function upvote(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('upvote', $report);

        /** @var User $citizen */
        $citizen = $request->user();

        return ApiResponse::success($this->citizenService->upvoteReport($report, $citizen));
    }

    public function watch(WatchReportRequest $request, Report $report): JsonResponse
    {
        /** @var User $citizen */
        $citizen = $request->user();

        return ApiResponse::success(
            $this->citizenService->updateWatchStatus(
                $report,
                $citizen,
                (bool) $request->validated('is_watching'),
            )
        );
    }

    public function index(CitizenReportsRequest $request): JsonResponse
    {
        /** @var User $citizen */
        $citizen = $request->user();
        $paginator = $this->citizenService->listCitizenReports($citizen, $request->validated());

        return ApiResponse::success(
            CitizenReportResource::collection($paginator->getCollection())->resolve($request),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }
}
