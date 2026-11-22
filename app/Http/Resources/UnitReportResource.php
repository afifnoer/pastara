<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;

class UnitReportResource extends BaseReportResource
{
    protected function formatReporter(Report $report): ?array
    {
        // Hukum 4 Konstitusi: identik wajib dilindungi untuk tiket Mode Rahasia
        if ($report->is_secret) {
            return [
                'name' => 'Pelapor Rahasia',
                'email' => null,
                'phone' => null,
            ];
        }

        $reporter = $report->relationLoaded('reporter') ? $report->reporter : null;

        return [
            'name' => $reporter?->name,
            'email' => null,
            'phone' => $this->maskPhone($reporter?->phone),
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

        return [
            'id' => $officer->id,
            'name' => $officer->name,
            'phone' => $officer->phone,
        ];
    }

    protected function formatAuditTimeline(Report $report): array
    {
        return $this->buildAuditItems($report, onlyPublicVisible: false, includeMetadata: false);
    }
}
