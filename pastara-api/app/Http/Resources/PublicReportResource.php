<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;

class PublicReportResource extends BaseReportResource
{
    protected function formatReporter(Report $report, Request $request): ?array
    {
        if ($report->is_secret) {
            return [
                'name' => 'Pelapor Rahasia',
            ];
        }

        $reporter = $report->relationLoaded('reporter') ? $report->reporter : null;

        return [
            'name' => $this->maskName($reporter?->name),
        ];
    }

    protected function formatAssignedOfficer(Report $report): ?array
    {
        if ($report->assigned_officer_id === null) {
            return null;
        }

        $unitName = $report->relationLoaded('opdUnit') ? $report->opdUnit?->name : null;

        return [
            'role_label' => 'Petugas Lapangan',
            'unit_name' => $unitName,
        ];
    }

    protected function formatAuditTimeline(Report $report): array
    {
        return $this->buildAuditItems($report, onlyPublicVisible: true, includeMetadata: false);
    }
}
