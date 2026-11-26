<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;

class AuditReportResource extends BaseReportResource
{
    protected function formatReporter(Report $report, Request $request): ?array
    {
        $reporter = $report->relationLoaded('reporter') ? $report->reporter : null;
        if ($reporter === null) {
            return null;
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

        $officer = $report->relationLoaded('assignedOfficer') ? $report->assignedOfficer : null;
        if ($officer === null) {
            return null;
        }

        $unitName = $report->relationLoaded('opdUnit') ? $report->opdUnit?->name : null;

        return [
            'id' => $officer->id,
            'name' => $officer->name,
            'email' => $officer->email,
            'phone' => $officer->phone,
            'unit_name' => $unitName,
        ];
    }

    protected function formatAuditTimeline(Report $report): array
    {
        return $this->buildAuditItems($report, onlyPublicVisible: false, includeMetadata: true);
    }
}
