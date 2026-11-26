<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inspectorate\InspectorateReportsRequest;
use App\Http\Requests\Inspectorate\InspectorateScorecardRequest;
use App\Http\Requests\Inspectorate\ResolveDisputeRequest;
use App\Http\Resources\AuditReportResource;
use App\Models\Dispute;
use App\Models\User;
use App\Services\InspectorateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class InspectorateController extends Controller
{
    public function __construct(
        private readonly InspectorateService $inspectorateService,
    ) {}

    public function reports(InspectorateReportsRequest $request): JsonResponse
    {
        $paginator = $this->inspectorateService->listReports($request->validated());

        return ApiResponse::success(
            AuditReportResource::collection($paginator->getCollection())->resolve($request),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function resolveDispute(ResolveDisputeRequest $request, Dispute $dispute): JsonResponse
    {
        /** @var User $auditor */
        $auditor = $request->user();
        $validated = $request->validated();

        $updated = $this->inspectorateService->resolveDispute(
            dispute: $dispute,
            auditor: $auditor,
            decision: (string) $validated['decision'],
            auditNotes: (string) $validated['audit_notes'],
            reopenSlaHours: isset($validated['reopen_sla_hours']) ? (int) $validated['reopen_sla_hours'] : null,
        );

        return ApiResponse::success(new AuditReportResource($updated));
    }

    public function scorecard(InspectorateScorecardRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            $this->inspectorateService->getInternalScorecard(
                month: isset($validated['month']) ? (int) $validated['month'] : null,
                year: isset($validated['year']) ? (int) $validated['year'] : null,
                sort: $validated['sort'] ?? null,
            )
        );
    }
}
