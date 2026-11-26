<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditActionType;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Services\AuditLoggerService;
use App\Services\NotificationDispatcherService;
use App\Services\SlaEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EvaluateSlaCommand extends Command
{
    protected $signature = 'pastara:evaluate-sla';

    protected $description = 'Evaluasi zona SLA (Zona Kuning >= 70% & Overdue Merah >= 100%) secara idempoten (FR-034, BR-009).';

    public function handle(
        SlaEngineService $slaEngine,
        AuditLoggerService $auditLogger,
        NotificationDispatcherService $notifier,
    ): int {
        $warningsTriggered = 0;
        $overduesTriggered = 0;

        Report::query()
            ->select(['id'])
            ->whereIn('status', [
                ReportStatus::IN_PROGRESS->value,
                ReportStatus::REOPENED_BY_AUDIT->value,
            ])
            ->whereNotNull('sla_cycle_started_at')
            ->whereNotNull('sla_deadline_at')
            ->orderBy('id')
            ->chunkById(100, function ($reports) use (
                $slaEngine,
                $auditLogger,
                $notifier,
                &$warningsTriggered,
                &$overduesTriggered,
            ): void {
                foreach ($reports as $reportRow) {
                    $outcome = DB::transaction(function () use (
                        $reportRow,
                        $slaEngine,
                        $auditLogger,
                        $notifier,
                    ): ?string {
                        $locked = Report::query()->whereKey($reportRow->id)->lockForUpdate()->first();
                        if (
                            $locked === null
                            || ! $locked->status->isSlaRunning()
                            || $locked->sla_cycle_started_at === null
                            || $locked->sla_deadline_at === null
                        ) {
                            return null;
                        }

                        $now = CarbonImmutable::now('UTC');
                        $ratio = $slaEngine->calculateRatio($locked);

                        // 1. Overdue Merah (R >= 100% / now >= sla_deadline_at)
                        if ($now->gte($locked->sla_deadline_at) || ($ratio !== null && $ratio >= 1.0)) {
                            if ($locked->is_sla_overdue) {
                                return null;
                            }

                            $locked->update([
                                'is_sla_overdue' => true,
                                'is_late' => true,
                            ]);

                            $auditLogger->log(
                                actionType: AuditActionType::SLA_OVERDUE,
                                report: $locked,
                                actor: null,
                                fromStatus: $locked->status,
                                toStatus: $locked->status,
                                note: 'Batas waktu SLA telah terlewati (Zona Merah).',
                                metadata: [
                                    'cycle' => $locked->current_cycle,
                                    'sla_deadline_at' => $locked->sla_deadline_at->toIso8601ZuluString(),
                                ],
                                actorRoleOverride: 'SYSTEM',
                            );

                            $recipients = array_merge(
                                $notifier->getUnitAdminIds((int) $locked->opd_unit_id),
                                $locked->assigned_officer_id !== null ? [(int) $locked->assigned_officer_id] : [],
                                $notifier->getInspectorateIds(),
                            );

                            $notifier->notifyUsers(
                                $recipients,
                                NotificationType::SLA_OVERDUE_RED,
                                $locked,
                                "Batas waktu SLA tiket {$locked->ticket_code} telah terlewati (Zona Merah)."
                            );

                            return 'overdue';
                        }

                        // 2. Peringatan Zona Kuning (70% <= R < 100%)
                        if (
                            $ratio !== null
                            && $ratio >= 0.70
                            && $ratio < 1.0
                            && $locked->sla_warning_notified_at === null
                            && ! $locked->is_sla_overdue
                        ) {
                            $locked->update([
                                'sla_warning_notified_at' => $now,
                            ]);

                            $auditLogger->log(
                                actionType: AuditActionType::SLA_WARNING,
                                report: $locked,
                                actor: null,
                                fromStatus: $locked->status,
                                toStatus: $locked->status,
                                note: 'Durasi SLA memasuki Zona Kuning (>= 70% batas waktu berlalu).',
                                metadata: [
                                    'cycle' => $locked->current_cycle,
                                    'ratio' => round($ratio, 4),
                                ],
                                actorRoleOverride: 'SYSTEM',
                            );

                            $recipients = array_merge(
                                $notifier->getUnitAdminIds((int) $locked->opd_unit_id),
                                $locked->assigned_officer_id !== null ? [(int) $locked->assigned_officer_id] : [],
                            );

                            $notifier->notifyUsers(
                                $recipients,
                                NotificationType::SLA_WARNING_YELLOW,
                                $locked,
                                "Tiket {$locked->ticket_code} telah memasuki Zona Kuning SLA (>= 70% durasi berlalu)."
                            );

                            return 'warning';
                        }

                        return null;
                    });

                    if ($outcome === 'warning') {
                        $warningsTriggered++;
                    } elseif ($outcome === 'overdue') {
                        $overduesTriggered++;
                    }
                }
            });

        $this->info("SLA evaluation complete: {$warningsTriggered} yellow warning(s), {$overduesTriggered} overdue(s).");

        return self::SUCCESS;
    }
}
