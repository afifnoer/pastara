<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditActionType;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Models\BudgetMilestone;
use App\Models\BudgetSchedule;
use App\Models\Report;
use App\Services\AuditLoggerService;
use App\Services\NotificationDispatcherService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EvaluateApbdMilestonesCommand extends Command
{
    protected $signature = 'pastara:evaluate-apbd-milestones';

    protected $description = 'Evaluasi harian 3 kewajiban milestone Jalur 2 APBD (BR-010, BR-011, BR-012) secara idempoten (FR-036).';

    public function handle(
        AuditLoggerService $auditLogger,
        NotificationDispatcherService $notifier,
    ): int {
        $flaggedCount = 0;

        Report::query()
            ->select(['id'])
            ->where('status', ReportStatus::BUDGET_SCHEDULED->value)
            ->where('is_milestone_overdue', false)
            ->orderBy('id')
            ->chunkById(100, function ($reports) use ($auditLogger, $notifier, &$flaggedCount): void {
                foreach ($reports as $reportRow) {
                    $flagged = DB::transaction(function () use ($reportRow, $auditLogger, $notifier): bool {
                        $locked = Report::query()->whereKey($reportRow->id)->lockForUpdate()->first();
                        if (
                            $locked === null
                            || $locked->status !== ReportStatus::BUDGET_SCHEDULED
                            || $locked->is_milestone_overdue
                        ) {
                            return false;
                        }

                        $schedule = BudgetSchedule::query()->where('report_id', $locked->id)->first();
                        if ($schedule === null) {
                            return false;
                        }

                        $nowUtc = CarbonImmutable::now('UTC');
                        $violations = $this->evaluateViolations($schedule, $nowUtc);
                        if ($violations === []) {
                            return false;
                        }

                        $locked->update([
                            'is_milestone_overdue' => true,
                        ]);

                        $note = implode(' ', array_values($violations));

                        $auditLogger->log(
                            actionType: AuditActionType::MILESTONE_OVERDUE,
                            report: $locked,
                            actor: null,
                            fromStatus: ReportStatus::BUDGET_SCHEDULED,
                            toStatus: ReportStatus::BUDGET_SCHEDULED,
                            note: $note,
                            metadata: [
                                'violations' => array_keys($violations),
                                'fiscal_year' => $schedule->fiscal_year,
                                'target_quarter' => $schedule->target_quarter,
                            ],
                            actorRoleOverride: 'SYSTEM',
                        );

                        $recipients = array_merge(
                            $notifier->getUnitAdminIds((int) $locked->opd_unit_id),
                            $notifier->getInspectorateIds(),
                        );

                        $notifier->notifyUsers(
                            $recipients,
                            NotificationType::APBD_MILESTONE_OVERDUE,
                            $locked,
                            "Kewajiban milestone APBD tiket {$locked->ticket_code} terlewati: {$note}"
                        );

                        return true;
                    });

                    if ($flagged) {
                        $flaggedCount++;
                    }
                }
            });

        $this->info("APBD milestone evaluation complete: {$flaggedCount} ticket(s) flagged overdue.");

        return self::SUCCESS;
    }

    /**
     * Evaluate BR-010 (7-weekday RKA doc), BR-011 (quarterly update), and BR-012 (target quarter elapsed).
     *
     * @return array<string, string>
     */
    private function evaluateViolations(BudgetSchedule $schedule, CarbonImmutable $nowUtc): array
    {
        $violations = [];
        $nowWib = $nowUtc->setTimezone('Asia/Jakarta');

        // BR-010: Dokumen RKA/bukti usulan wajib diunggah <= 7 hari kerja
        if (
            $schedule->document_uploaded_at === null
            && $schedule->document_due_at !== null
            && $nowUtc->gt($schedule->document_due_at)
        ) {
            $violations['BR-010'] = 'Dokumen bukti usulan/RKA APBD belum diunggah melewati tenggat 7 hari kerja.';
        }

        // BR-011: Minimal 1 pembaruan milestone setiap triwulan kalender selama BUDGET_SCHEDULED
        $currentQuarterStartWib = $nowWib->firstOfQuarter()->startOfDay();
        $scheduledAtWib = $schedule->created_at?->setTimezone('Asia/Jakarta');

        if ($scheduledAtWib !== null && $scheduledAtWib->lt($currentQuarterStartWib)) {
            $previousQuarterStartUtc = $currentQuarterStartWib->subQuarter()->utc();

            $hasRecentMilestone = BudgetMilestone::query()
                ->where('budget_schedule_id', $schedule->id)
                ->where('created_at', '>=', $previousQuarterStartUtc)
                ->exists();

            if (! $hasRecentMilestone) {
                $violations['BR-011'] = 'Tidak ada pembaruan milestone triwulanan pada kuartal kalender sebelumnya.';
            }
        }

        // BR-012: Target triwulan pelaksanaan berakhir tanpa Mulai Eksekusi
        $quarterEndUtc = $this->resolveQuarterEndUtc((int) $schedule->fiscal_year, (string) $schedule->target_quarter);
        if ($nowUtc->gt($quarterEndUtc)) {
            $violations['BR-012'] = "Target kuartal pelaksanaan ({$schedule->fiscal_year}-{$schedule->target_quarter}) telah berakhir tanpa mulai eksekusi.";
        }

        return $violations;
    }

    private function resolveQuarterEndUtc(int $fiscalYear, string $targetQuarter): CarbonImmutable
    {
        $endMonth = match (strtoupper($targetQuarter)) {
            'Q1' => 3,
            'Q2' => 6,
            'Q3' => 9,
            default => 12,
        };

        return CarbonImmutable::create($fiscalYear, $endMonth, 1, 0, 0, 0, 'Asia/Jakarta')
            ->endOfMonth()
            ->utc();
    }
}
