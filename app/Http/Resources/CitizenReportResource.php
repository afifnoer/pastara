<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;

class CitizenReportResource extends BaseReportResource
{
    protected function formatReporter(Report $report, Request $request): ?array
    {
        $reporter = $report->relationLoaded('reporter') ? $report->reporter : null;
        if ($reporter === null) {
            return null;
        }

        $viewer = $request->user('sanctum') ?? $request->user();
        $isOwner = $viewer !== null && (int) $viewer->id === (int) $report->reporter_id;

        if (! $isOwner) {
            return [
                'name' => $report->is_secret ? 'Pelapor Rahasia' : $this->maskName($reporter->name),
            ];
        }

        return [
            'id' => $reporter->id,
            'name' => $reporter->name,
            'email' => $reporter->email,
            'phone' => $reporter->phone,
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
