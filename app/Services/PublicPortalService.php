<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\Village;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PublicPortalService
{
    /**
     * Full relation graph needed by PublicReportResource without triggering lazy loading.
     *
     * @var list<string>
     */
    private const DETAIL_RELATIONS = [
        'reporter',
        'category',
        'opdUnit',
        'assignedOfficer',
        'district',
        'village',
        'photos',
        'budgetSchedule.milestones',
        'disputes',
        'rating',
        'auditLogs',
    ];

    public function __construct(
        private readonly SlaEngineService $slaEngine,
        private readonly ScorecardCalculatorService $scorecardCalculator,
    ) {}

    /**
     * Return active reference master data for public portal filters and report submission forms.
     *
     * @return array<string, mixed>
     */
    public function getReferenceData(): array
    {
        $categories = Category::query()
            ->active()
            ->orderBy('code')
            ->get([
                'id',
                'code',
                'name',
                'description',
                'default_opd_unit_id',
                'route_by_village',
                'default_sla_hours',
                'issue_type',
                'duplicate_radius_m',
                'geotag_tolerance_m',
                'recommend_secret',
            ]);

        $opdUnits = OpdUnit::query()
            ->active()
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'level', 'description']);

        $districts = District::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $villages = Village::query()
            ->orderBy('name')
            ->get(['id', 'district_id', 'opd_unit_id', 'name', 'type']);

        return [
            'categories' => $categories,
            'opd_units' => $opdUnits,
            'districts' => $districts,
            'villages' => $villages,
            'cilacap_bounds' => config('pastara.cilacap'),
        ];
    }

    /**
     * Return public map markers, strictly excluding secret reports (Hukum 4 — BR-026).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getMapMarkers(array $filters): array
    {
        $query = Report::query()
            ->with([
                'category:id,code,name,issue_type',
                'opdUnit:id,code,name',
            ])
            ->where('is_secret', false);

        $this->applyPublicFilters($query, $filters);

        if (! empty($filters['bbox'])) {
            $parts = array_map('floatval', explode(',', (string) $filters['bbox']));
            if (count($parts) === 4) {
                [$minLat, $minLng, $maxLat, $maxLng] = $parts;
                $query->whereBetween('latitude', [min($minLat, $maxLat), max($minLat, $maxLat)])
                    ->whereBetween('longitude', [min($minLng, $maxLng), max($minLng, $maxLng)]);
            }
        }

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Report $report): array {
                return [
                    'id' => $report->id,
                    'ticket_code' => $report->ticket_code,
                    'title' => $report->title,
                    'status' => $report->status->value,
                    'status_label' => $report->status->label(),
                    'status_badge_color' => $report->status->badgeColor(),
                    'latitude' => (float) $report->latitude,
                    'longitude' => (float) $report->longitude,
                    'upvote_count' => $report->upvote_count,
                    'sla_zone' => $this->slaEngine->calculateZone($report)->value,
                    'sla_remaining_seconds' => $this->slaEngine->remainingSeconds($report),
                    'is_sla_overdue' => $report->is_sla_overdue,
                    'is_milestone_overdue' => $report->is_milestone_overdue,
                    'category' => $report->category !== null ? [
                        'id' => $report->category->id,
                        'code' => $report->category->code,
                        'name' => $report->category->name,
                        'issue_type' => $report->category->issue_type->value,
                    ] : null,
                    'opd_unit' => $report->opdUnit !== null ? [
                        'id' => $report->opdUnit->id,
                        'code' => $report->opdUnit->code,
                        'name' => $report->opdUnit->name,
                    ] : null,
                    'created_at' => $report->created_at?->toIso8601ZuluString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Browse paginated public (non-secret) reports with search, filters, and sort options.
     *
     * @param  array<string, mixed>  $filters
     */
    public function browseReports(array $filters): LengthAwarePaginator
    {
        $query = Report::query()
            ->with(['category', 'opdUnit', 'district', 'village', 'photos'])
            ->where('is_secret', false);

        $this->applyPublicFilters($query, $filters);

        $sort = (string) ($filters['sort'] ?? 'latest');
        match ($sort) {
            'upvotes' => $query->orderByDesc('upvote_count')->orderByDesc('created_at')->orderByDesc('id'),
            'sla' => $query->orderByDesc('is_sla_overdue')
                ->orderByRaw('sla_deadline_at IS NULL ASC')
                ->orderBy('sla_deadline_at')
                ->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query->paginate(20);
    }

    /**
     * Load a bare Report row by ticket code prior to policy authorization (avoids pre-auth eager loading).
     */
    public function getReportForPublicDetail(string $ticketCode): Report
    {
        return Report::query()
            ->where('ticket_code', strtoupper(trim($ticketCode)))
            ->firstOrFail();
    }

    /**
     * Eager-load detail relations and viewer-specific has_upvoted flag after authorization passes.
     */
    public function loadDetailRelations(Report $report, ?int $viewerId = null): Report
    {
        $report->load(self::DETAIL_RELATIONS);
        $report->setAttribute(
            'has_upvoted',
            $viewerId !== null && $report->upvotes()->where('user_id', $viewerId)->exists()
        );

        return $report;
    }

    /**
     * Track a public or secret report by ticket code and optional secret token.
     * Returns null when the ticket does not exist or the provided secret token is invalid.
     *
     * @return array<string, mixed>|null
     */
    public function trackReport(string $ticketCode, ?string $secretToken, ?int $viewerId = null): ?array
    {
        $normalizedCode = strtoupper(trim($ticketCode));
        $report = Report::query()
            ->where('ticket_code', $normalizedCode)
            ->first();

        if ($report === null) {
            return null;
        }

        if ($report->is_secret) {
            $token = trim((string) $secretToken);
            if ($token === '') {
                return [
                    'requires_secret_token' => true,
                    'ticket_code' => $report->ticket_code,
                ];
            }

            if (! $report->verifySecretToken($token)) {
                return null;
            }
        }

        $this->loadDetailRelations($report, $viewerId);

        return [
            'requires_secret_token' => false,
            'ticket_code' => $report->ticket_code,
            'report' => $report,
        ];
    }

    /**
     * Compute public accountability summary ("Statistik Jujur") and OPD scorecard table (strictly read-only).
     *
     * @return array<string, mixed>
     */
    public function getPublicScorecard(?int $month, ?int $year): array
    {
        [$startUtc, $endUtc] = $this->scorecardCalculator->resolvePeriodBounds($month, $year);

        $summaryRow = Report::query()
            ->when(
                $startUtc !== null && $endUtc !== null,
                fn (Builder $q): Builder => $q->whereBetween('created_at', [$startUtc, $endUtc])
            )
            ->selectRaw('COUNT(*) AS total_reports')
            ->selectRaw('COUNT(*) FILTER (WHERE status != ?) AS valid_tickets', [ReportStatus::REJECTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS rejected_tickets', [ReportStatus::REJECTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS budget_scheduled_apbd', [ReportStatus::BUDGET_SCHEDULED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?)) AS verified_closed_physical', [
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS closed_by_audit', [ReportStatus::CLOSED_BY_AUDIT->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?, ?, ?, ?)) AS active_tickets', [
                ReportStatus::SUBMITTED->value,
                ReportStatus::ROUTED_TO_UNIT->value,
                ReportStatus::IN_PROGRESS->value,
                ReportStatus::PENDING_CITIZEN_SIGN_OFF->value,
                ReportStatus::DISPUTED->value,
                ReportStatus::REOPENED_BY_AUDIT->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status NOT IN (?, ?, ?, ?) AND (is_sla_overdue = TRUE OR is_milestone_overdue = TRUE)) AS overdue_reports', [
                ReportStatus::REJECTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])
            ->first();

        $opdScorecard = $this->scorecardCalculator
            ->calculateAllUnits($month, $year)
            ->map(static function (array $unit): array {
                unset($unit['secret_count']);

                return $unit;
            })
            ->values()
            ->all();

        $resolvedYear = $year ?? ($month !== null ? CarbonImmutable::now('Asia/Jakarta')->year : null);

        return [
            'period' => [
                'month' => $month,
                'year' => $resolvedYear,
                'timezone' => 'Asia/Jakarta',
            ],
            'summary' => [
                'total_reports' => (int) ($summaryRow->total_reports ?? 0),
                'valid_tickets' => (int) ($summaryRow->valid_tickets ?? 0),
                'rejected_tickets' => (int) ($summaryRow->rejected_tickets ?? 0),
                'budget_scheduled_apbd' => (int) ($summaryRow->budget_scheduled_apbd ?? 0),
                'verified_closed_physical' => (int) ($summaryRow->verified_closed_physical ?? 0),
                'closed_by_audit' => (int) ($summaryRow->closed_by_audit ?? 0),
                'active_tickets' => (int) ($summaryRow->active_tickets ?? 0),
                'overdue_reports' => (int) ($summaryRow->overdue_reports ?? 0),
            ],
            'opd_scorecard' => $opdScorecard,
        ];
    }

    /**
     * Apply shared public query filters across map markers and paginated report browsing.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyPublicFilters(Builder $query, array $filters): void
    {
        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['opd_unit_id'])) {
            $query->where('opd_unit_id', (int) $filters['opd_unit_id']);
        }

        if (isset($filters['district_id'])) {
            $query->where('district_id', (int) $filters['district_id']);
        }

        if (filter_var($filters['overdue_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(function (Builder $sub): void {
                $sub->where('is_sla_overdue', true)
                    ->orWhere('is_milestone_overdue', true);
            });
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $pattern = '%'.$escaped.'%';
            $query->where(function (Builder $sub) use ($pattern): void {
                $sub->where('ticket_code', 'ilike', $pattern)
                    ->orWhere('title', 'ilike', $pattern)
                    ->orWhere('description_sanitized', 'ilike', $pattern)
                    ->orWhere('address_detail', 'ilike', $pattern);
            });
        }
    }
}
