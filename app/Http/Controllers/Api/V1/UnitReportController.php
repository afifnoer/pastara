<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\RejectionReasonCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Unit\AssignOfficerRequest;
use App\Http\Requests\Unit\ReassignOfficerRequest;
use App\Http\Requests\Unit\RejectReportRequest;
use App\Http\Requests\Unit\RerouteReportRequest;
use App\Http\Requests\Unit\UnitReportsRequest;
use App\Http\Resources\UnitReportResource;
use App\Models\Report;
use App\Models\User;
use App\Services\UnitReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UnitReportController extends Controller
{
    public function __construct(
        private readonly UnitReportService $unitService,
    ) {}

    public function index(UnitReportsRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $paginator = $this->unitService->listUnitReports($admin, $request->validated());

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

    public function show(Report $report): JsonResponse
    {
        Gate::authorize('viewUnit', $report);

        return ApiResponse::success(new UnitReportResource($this->unitService->loadUnitDetail($report)));
    }

    public function officers(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        return ApiResponse::success($this->unitService->listUnitOfficers($admin));
    }

    public function assign(AssignOfficerRequest $request, Report $report): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $validated = $request->validated();

        $updated = $this->unitService->assignOperational(
            $report,
            $admin,
            (int) $validated['officer_id'],
            $validated['note'] ?? null,
        );

        return ApiResponse::success(new UnitReportResource($updated));
    }

    public function reassignOfficer(ReassignOfficerRequest $request, Report $report): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $validated = $request->validated();

        $updated = $this->unitService->reassignOfficer(
            $report,
            $admin,
            (int) $validated['officer_id'],
            (string) $validated['reason'],
        );

        return ApiResponse::success(new UnitReportResource($updated));
    }

    public function reject(RejectReportRequest $request, Report $report): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $validated = $request->validated();

        $updated = $this->unitService->rejectReport(
            $report,
            $admin,
            RejectionReasonCode::from((string) $validated['reason_code']),
            (string) $validated['note'],
        );

        return ApiResponse::success(new UnitReportResource($updated));
    }

    public function reroute(RerouteReportRequest $request, Report $report): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $validated = $request->validated();

        $updated = $this->unitService->rerouteReport(
            $report,
            $admin,
            (int) $validated['target_opd_unit_id'],
            isset($validated['new_category_id']) ? (int) $validated['new_category_id'] : null,
            (string) $validated['note'],
        );

        return ApiResponse::success(new UnitReportResource($updated));
    }
}
