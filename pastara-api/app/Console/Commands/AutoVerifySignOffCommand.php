<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Report;
use App\Services\NotificationDispatcherService;
use App\Services\ReportStateMachineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoVerifySignOffCommand extends Command
{
    protected $signature = 'pastara:auto-verify-signoff';

    protected $description = 'Kirim pengingat sisa <= 24 jam dan auto-verifikasi Escrow lewat 72 jam secara idempoten (TR-10, FR-031, FR-035).';

    public function handle(
        ReportStateMachineService $stateMachine,
        NotificationDispatcherService $notifier,
    ): int {
        $autoVerified = 0;
        $remindersSent = 0;
        $reminderHours = (int) config('pastara.signoff_reminder_hours', 24);
        $reminderThresholdSeconds = $reminderHours * 3600;

        Report::query()
            ->select(['id', 'signoff_deadline_at', 'signoff_reminder_sent_at'])
            ->where('status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
            ->whereNotNull('signoff_deadline_at')
            ->orderBy('id')
            ->chunkById(100, function ($reports) use (
                $stateMachine,
                $notifier,
                $reminderHours,
                $reminderThresholdSeconds,
                &$autoVerified,
                &$remindersSent,
            ): void {
                foreach ($reports as $candidate) {
                    $now = CarbonImmutable::now('UTC');

                    // 1. Jendela 72 jam telah berakhir -> TR-10 AUTO_VERIFIED_CLOSED
                    if ($candidate->signoff_deadline_at !== null && $now->gte($candidate->signoff_deadline_at)) {
                        try {
                            $stateMachine->autoVerifyExpired($candidate);
                            $autoVerified++;
                        } catch (InvalidStateTransitionException) {
                            // Ticket concurrently approved/vetoed/closed before lock; safely skip
                        }

                        continue;
                    }

                    // 2. Pengingat sisa <= 24 jam (sekali kirim, idempoten)
                    if (
                        $candidate->signoff_reminder_sent_at === null
                        && $candidate->signoff_deadline_at !== null
                        && $now->diffInSeconds($candidate->signoff_deadline_at, false) <= $reminderThresholdSeconds
                    ) {
                        $sent = DB::transaction(function () use (
                            $candidate,
                            $notifier,
                            $reminderHours,
                            $reminderThresholdSeconds,
                        ): bool {
                            $locked = Report::query()->whereKey($candidate->id)->lockForUpdate()->first();
                            $lockedNow = CarbonImmutable::now('UTC');

                            if (
                                $locked === null
                                || $locked->status !== ReportStatus::PENDING_CITIZEN_SIGN_OFF
                                || $locked->signoff_deadline_at === null
                                || $locked->signoff_reminder_sent_at !== null
                                || $lockedNow->gte($locked->signoff_deadline_at)
                                || $lockedNow->diffInSeconds($locked->signoff_deadline_at, false) > $reminderThresholdSeconds
                            ) {
                                return false;
                            }

                            $locked->update([
                                'signoff_reminder_sent_at' => $lockedNow,
                            ]);

                            $notifier->notifyUsers(
                                [(int) $locked->reporter_id],
                                NotificationType::SIGN_OFF_REMINDER_24H,
                                $locked,
                                "Sisa waktu konfirmasi penyelesaian tiket {$locked->ticket_code} kurang dari {$reminderHours} jam sebelum ditutup otomatis."
                            );

                            return true;
                        });

                        if ($sent) {
                            $remindersSent++;
                        }
                    }
                }
            });

        $this->info("Sign-off scheduler complete: {$autoVerified} auto-verified, {$remindersSent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
