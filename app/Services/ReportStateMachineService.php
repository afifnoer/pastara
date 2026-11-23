<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Enums\UserRole;
use App\Exceptions\EscrowLockViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\BudgetSchedule;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

class ReportStateMachineService
{
    private const TOKEN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AuditLoggerService $auditLogger,
        private readonly NotificationDispatcherService $notifier,
        private readonly TextSanitizerService $sanitizer,
        private readonly GeotagValidationService $geotagValidator,
        private readonly SlaEngineService $slaEngine,
    ) {}

    /**
     * Guard against unilateral closure attempts by internal roles (Hukum 1 — BR-001).
     */
    public function guardUnilateralClose(User $actor, ReportStatus $targetStatus): void
    {
        $isInternalRole = in_array(
            $actor->role,
            [UserRole::ADMIN_UNIT, UserRole::PETUGAS, UserRole::SUPER_ADMIN],
            true
        );

        $isEscrowProtectedTarget = in_array(
            $targetStatus,
            [ReportStatus::VERIFIED_CLOSED, ReportStatus::AUTO_VERIFIED_CLOSED],
            true
        );

        if ($isInternalRole && $isEscrowProtectedTarget) {
            Log::channel('security')->warning('Escrow Lock violation: internal role attempted unilateral report closure', [
                'actor_user_id' => $actor->id,
                'actor_role' => $actor->role->value,
                'target_status' => $targetStatus->value,
            ]);

            throw new EscrowLockViolationException;
        }
    }

    /**
     * TR-01: Create a new geospatial report, sanitize PII, auto-route to unit, and save before photos.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<UploadedFile>  $beforePhotos
     * @return array{report: Report, secret_token: ?string}
     */
    public function createAndRoute(User $reporter, array $payload, array $beforePhotos): array
    {
        if ($reporter->role !== UserRole::WARGA || ! $reporter->is_active) {
            throw new AuthorizationException('Hanya akun warga aktif yang dapat mengirim laporan baru.');
        }

        if ($beforePhotos === [] || count($beforePhotos) > 3) {
            throw ValidationException::withMessages([
                'photos' => ['Jumlah foto kondisi awal (Before) wajib antara 1 hingga 3 foto.'],
            ]);
        }

        $lat = (float) $payload['latitude'];
        $lng = (float) $payload['longitude'];
        $bounds = config('pastara.cilacap');
        if (
            $lat < (float) $bounds['min_lat'] || $lat > (float) $bounds['max_lat']
            || $lng < (float) $bounds['min_lng'] || $lng > (float) $bounds['max_lng']
        ) {
            throw ValidationException::withMessages([
                'latitude' => ['Koordinat laporan harus berada di dalam wilayah Kabupaten Cilacap.'],
            ]);
        }

        $writtenFiles = [];

        try {
            return DB::transaction(function () use ($reporter, $payload, $beforePhotos, $lat, $lng, &$writtenFiles): array {
                $category = Category::query()->whereKey((int) $payload['category_id'])->firstOrFail();
                if (! $category->is_active) {
                    throw ValidationException::withMessages([
                        'category_id' => ['Kategori yang dipilih sedang tidak aktif.'],
                    ]);
                }

                $village = Village::query()->with('opdUnit')->whereKey((int) $payload['village_id'])->firstOrFail();
                if ((int) $village->district_id !== (int) $payload['district_id']) {
                    throw ValidationException::withMessages([
                        'village_id' => ['Desa/kelurahan yang dipilih tidak berada pada kecamatan tersebut.'],
                    ]);
                }

                $targetUnit = $this->resolveTargetUnit($category, $village);
                $isSecret = (bool) ($payload['is_secret'] ?? false);
                $plainToken = $isSecret ? $this->generateSecretToken() : null;
                $now = CarbonImmutable::now('UTC');

                $rawAddress = isset($payload['address_detail']) ? trim((string) $payload['address_detail']) : '';
                $sanitizedAddress = $rawAddress !== ''
                    ? mb_substr($this->sanitizer->sanitize($rawAddress), 0, 255)
                    : null;

                $report = Report::create([
                    'ticket_code' => $this->generateUniqueTicketCode(),
                    'reporter_id' => $reporter->id,
                    'category_id' => $category->id,
                    'opd_unit_id' => $targetUnit->id,
                    'district_id' => (int) $payload['district_id'],
                    'village_id' => $village->id,
                    'title' => mb_substr($this->sanitizer->sanitize((string) $payload['title']), 0, 120),
                    'description_sanitized' => $this->sanitizer->sanitize((string) $payload['description']),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'address_detail' => $sanitizedAddress,
                    'is_secret' => $isSecret,
                    'secret_token_hash' => $plainToken !== null ? Report::hashSecretToken($plainToken) : null,
                    'status' => ReportStatus::ROUTED_TO_UNIT,
                    'current_cycle' => 1,
                    'issue_type_snapshot' => $category->issue_type,
                    'duplicate_radius_snapshot_m' => $category->duplicate_radius_m,
                    'geotag_tolerance_snapshot_m' => $category->geotag_tolerance_m,
                    'sla_hours_snapshot' => $category->default_sla_hours,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach (array_values($beforePhotos) as $index => $photoFile) {
                    $photo = $this->geotagValidator->processUpload(
                        report: $report,
                        file: $photoFile,
                        type: PhotoType::BEFORE,
                        cycle: 1,
                        sequence: $index + 1,
                        uploadedByUserId: $reporter->id,
                    );
                    $writtenFiles[] = $photo->file_path;
                    $writtenFiles[] = $photo->thumbnail_path;
                }

                $this->auditLogger->log(
                    actionType: AuditActionType::SUBMITTED,
                    report: $report,
                    actor: $reporter,
                    fromStatus: null,
                    toStatus: ReportStatus::SUBMITTED,
                    note: 'Laporan dikirim oleh warga.',
                );

                $this->auditLogger->log(
                    actionType: AuditActionType::ROUTED,
                    report: $report,
                    actor: null,
                    fromStatus: ReportStatus::SUBMITTED,
                    toStatus: ReportStatus::ROUTED_TO_UNIT,
                    note: "Dirutekan otomatis ke {$targetUnit->name}.",
                    metadata: ['opd_unit_code' => $targetUnit->code],
                );

                $this->notifier->notifyUsers(
                    $this->notifier->getUnitAdminIds($targetUnit->id),
                    NotificationType::REPORT_ROUTED,
                    $report,
                    "Laporan baru {$report->ticket_code} masuk untuk ditindaklanjuti unit {$targetUnit->name}."
                );

                return [
                    'report' => $report->fresh(['category', 'opdUnit', 'district', 'village', 'photos']),
                    'secret_token' => $plainToken,
                ];
            });
        } catch (Throwable $e) {
            if ($writtenFiles !== []) {
                Storage::disk('public')->delete($writtenFiles);
            }

            throw $e;
        }
    }

    /**
     * TR-02: Reroute report to another active unit (optionally updating category and snapshots).
     */
    public function reroute(
        Report $report,
        User $admin,
        int $targetUnitId,
        ?int $newCategoryId,
        string $note,
    ): Report {
        return DB::transaction(function () use ($report, $admin, $targetUnitId, $newCategoryId, $note): Report {
            $locked = $this->lockReport($report);
            $this->assertUnitAdmin($admin, $locked);
            $this->assertStatus($locked, ReportStatus::ROUTED_TO_UNIT);

            if ($targetUnitId === (int) $locked->opd_unit_id) {
                throw ValidationException::withMessages([
                    'target_opd_unit_id' => ['Unit tujuan harus berbeda dari unit saat ini.'],
                ]);
            }

            $targetUnit = OpdUnit::query()->find($targetUnitId);
            if ($targetUnit === null || ! $targetUnit->is_active) {
                throw ValidationException::withMessages([
                    'target_opd_unit_id' => ['Unit tujuan tidak ditemukan atau sedang nonaktif.'],
                ]);
            }

            $fromUnitName = OpdUnit::query()->whereKey($locked->opd_unit_id)->value('name');
            $updates = ['opd_unit_id' => $targetUnit->id];

            if ($newCategoryId !== null && $newCategoryId !== (int) $locked->category_id) {
                $newCategory = Category::query()->find($newCategoryId);
                if ($newCategory === null || ! $newCategory->is_active) {
                    throw ValidationException::withMessages([
                        'new_category_id' => ['Kategori baru tidak ditemukan atau sedang nonaktif.'],
                    ]);
                }

                $updates += [
                    'category_id' => $newCategory->id,
                    'issue_type_snapshot' => $newCategory->issue_type,
                    'duplicate_radius_snapshot_m' => $newCategory->duplicate_radius_m,
                    'geotag_tolerance_snapshot_m' => $newCategory->geotag_tolerance_m,
                    'sla_hours_snapshot' => $newCategory->default_sla_hours,
                ];
            }

            $locked->update($updates);
            $sanitizedNote = $this->sanitizer->sanitize($note);

            $this->auditLogger->log(
                actionType: AuditActionType::REROUTED,
                report: $locked,
                actor: $admin,
                fromStatus: ReportStatus::ROUTED_TO_UNIT,
                toStatus: ReportStatus::ROUTED_TO_UNIT,
                note: $sanitizedNote,
                metadata: [
                    'from_unit_name' => $fromUnitName,
                    'to_unit_id' => $targetUnit->id,
                    'to_unit_name' => $targetUnit->name,
                ],
            );

            $this->notifier->notifyUsers(
                $this->notifier->getUnitAdminIds($targetUnit->id),
                NotificationType::REPORT_ROUTED,
                $locked,
                "Tiket {$locked->ticket_code} dialihkan dari {$fromUnitName} ke {$targetUnit->name}."
            );

            return $locked->fresh();
        });
    }

    /**
     * TR-03: Reject report during initial unit verification.
     */
    public function reject(Report $report, User $admin, RejectionReasonCode $reason, string $note): Report
    {
        return DB::transaction(function () use ($report, $admin, $reason, $note): Report {
            $locked = $this->lockReport($report);
            $this->assertUnitAdmin($admin, $locked);
            $this->assertStatus($locked, ReportStatus::ROUTED_TO_UNIT);

            $now = CarbonImmutable::now('UTC');
            $sanitizedNote = $this->sanitizer->sanitize($note);

            $locked->update([
                'status' => ReportStatus::REJECTED,
                'rejection_reason_code' => $reason,
                'rejection_note' => $sanitizedNote,
                'sla_stopped_at' => $now,
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::REJECTED,
                report: $locked,
                actor: $admin,
                fromStatus: ReportStatus::ROUTED_TO_UNIT,
                toStatus: ReportStatus::REJECTED,
                note: $sanitizedNote,
                metadata: ['reason_code' => $reason->value],
            );

            $this->notifier->notifyUsers(
                [$locked->reporter_id],
                NotificationType::REPORT_REJECTED,
                $locked,
                "Laporan {$locked->ticket_code} ditolak ({$reason->label()}): {$sanitizedNote}"
            );

            return $locked->fresh();
        });
    }

    /**
     * TR-04: Approve report on Track 1 (Operational) and start SLA clock.
     */
    public function assignOperational(Report $report, User $admin, User $officer, ?string $note = null): Report
    {
        return DB::transaction(function () use ($report, $admin, $officer, $note): Report {
            $locked = $this->lockReport($report);
            $this->assertUnitAdmin($admin, $locked);
            $this->assertStatus($locked, ReportStatus::ROUTED_TO_UNIT);

            $freshOfficer = User::query()->whereKey($officer->id)->lockForUpdate()->first();
            if ($freshOfficer === null) {
                throw ValidationException::withMessages([
                    'officer_id' => ['Petugas tidak ditemukan.'],
                ]);
            }
            $this->assertOfficerEligibleForUnit($freshOfficer, (int) $locked->opd_unit_id);

            $now = CarbonImmutable::now('UTC');
            $sanitizedNote = $note !== null ? $this->sanitizer->sanitize($note) : null;

            $locked->update([
                'status' => ReportStatus::IN_PROGRESS,
                'resolution_track' => ResolutionTrack::OPERATIONAL,
                'assigned_officer_id' => $freshOfficer->id,
                'sla_started_at' => $now,
                'sla_cycle_started_at' => $now,
                'sla_deadline_at' => $now->addHours($locked->sla_hours_snapshot),
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::ASSIGNED,
                report: $locked,
                actor: $admin,
                fromStatus: ReportStatus::ROUTED_TO_UNIT,
                toStatus: ReportStatus::IN_PROGRESS,
                note: $sanitizedNote ?? 'Petugas lapangan unit ditugaskan untuk penanganan operasional (Jalur 1).',
                metadata: ['officer_id' => $freshOfficer->id],
            );

            $recipients = array_merge($this->notifier->getReporterAndWatcherIds($locked), [$freshOfficer->id]);
            $this->notifier->notifyUsers(
                $recipients,
                NotificationType::REPORT_ASSIGNED,
                $locked,
                "Laporan {$locked->ticket_code} disetujui untuk penanganan operasional (SLA {$locked->sla_hours_snapshot} jam)."
            );

            return $locked->fresh();
        });
    }

    /**
     * TR-05: Schedule report onto Track 2 (APBD Budget) with 7-weekday RKA document deadline.
     *
     * @param  array<string, mixed>  $apbdData
     */
    public function scheduleApbd(
        Report $report,
        User $admin,
        array $apbdData,
        ?UploadedFile $docProof = null,
    ): Report {
        $writtenFiles = [];

        try {
            return DB::transaction(function () use ($report, $admin, $apbdData, $docProof, &$writtenFiles): Report {
                $locked = $this->lockReport($report);
                $this->assertUnitAdmin($admin, $locked);
                $this->assertStatus($locked, ReportStatus::ROUTED_TO_UNIT);

                $now = CarbonImmutable::now('UTC');
                $docPath = $docProof !== null ? $this->storeApbdDocument($docProof) : null;
                if ($docPath !== null) {
                    $writtenFiles[] = $docPath;
                }
                $sanitizedNote = $this->sanitizer->sanitize((string) ($apbdData['note'] ?? 'Dijadwalkan ke anggaran APBD.'));

                $locked->update([
                    'status' => ReportStatus::BUDGET_SCHEDULED,
                    'resolution_track' => ResolutionTrack::APBD,
                ]);

                BudgetSchedule::create([
                    'report_id' => $locked->id,
                    'fiscal_year' => (int) $apbdData['fiscal_year'],
                    'proposal_ref_number' => trim((string) $apbdData['proposal_ref_number']),
                    'target_quarter' => (string) $apbdData['target_quarter'],
                    'estimated_budget' => isset($apbdData['estimated_budget']) ? (float) $apbdData['estimated_budget'] : null,
                    'document_proof_path' => $docPath,
                    'document_due_at' => $this->slaEngine->calculateApbdDocumentDueAt($now),
                    'document_uploaded_at' => $docPath !== null ? $now : null,
                    'last_milestone_updated_at' => $now,
                    'created_by_user_id' => $admin->id,
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::BUDGET_SCHEDULED,
                    report: $locked,
                    actor: $admin,
                    fromStatus: ReportStatus::ROUTED_TO_UNIT,
                    toStatus: ReportStatus::BUDGET_SCHEDULED,
                    note: $sanitizedNote,
                    metadata: [
                        'fiscal_year' => (int) $apbdData['fiscal_year'],
                        'target_quarter' => (string) $apbdData['target_quarter'],
                        'proposal_ref_number' => trim((string) $apbdData['proposal_ref_number']),
                    ],
                );

                $this->notifier->notifyUsers(
                    $this->notifier->getReporterAndWatcherIds($locked),
                    NotificationType::REPORT_APBD_SCHEDULED,
                    $locked,
                    "Laporan {$locked->ticket_code} dijadwalkan pada anggaran APBD {$apbdData['fiscal_year']} ({$apbdData['target_quarter']})."
                );

                return $locked->fresh('budgetSchedule');
            });
        } catch (Throwable $e) {
            if ($writtenFiles !== []) {
                Storage::disk('public')->delete($writtenFiles);
            }

            throw $e;
        }
    }

    /**
     * TR-06: Start field execution of an APBD-scheduled report and begin SLA clock until target date 23:59 WIB.
     */
    public function startApbdExecution(
        Report $report,
        User $admin,
        string $targetDate,
        User $officer,
        ?string $note = null,
    ): Report {
        return DB::transaction(function () use ($report, $admin, $targetDate, $officer, $note): Report {
            $locked = $this->lockReport($report);
            $this->assertUnitAdmin($admin, $locked);
            $this->assertStatus($locked, ReportStatus::BUDGET_SCHEDULED);

            $freshOfficer = User::query()->whereKey($officer->id)->lockForUpdate()->first();
            if ($freshOfficer === null) {
                throw ValidationException::withMessages([
                    'officer_id' => ['Petugas tidak ditemukan.'],
                ]);
            }
            $this->assertOfficerEligibleForUnit($freshOfficer, (int) $locked->opd_unit_id);

            $now = CarbonImmutable::now('UTC');
            $trimmedTargetDate = trim($targetDate);

            try {
                $parsedTarget = CarbonImmutable::createFromFormat('!Y-m-d', $trimmedTargetDate, 'Asia/Jakarta');
            } catch (Throwable) {
                $parsedTarget = false;
            }

            if (! $parsedTarget instanceof CarbonImmutable || $parsedTarget->format('Y-m-d') !== $trimmedTargetDate) {
                throw ValidationException::withMessages([
                    'execution_target_date' => ['Format tanggal target eksekusi harus YYYY-MM-DD.'],
                ]);
            }

            $todayWib = $now->setTimezone('Asia/Jakarta')->startOfDay();
            if ($parsedTarget->lte($todayWib)) {
                throw ValidationException::withMessages([
                    'execution_target_date' => ['Tanggal target eksekusi APBD harus lebih besar dari hari ini (WIB).'],
                ]);
            }

            $slaDeadline = $parsedTarget->endOfDay()->utc();
            $normalizedTargetDate = $parsedTarget->toDateString();
            $schedule = BudgetSchedule::query()->where('report_id', $locked->id)->lockForUpdate()->first();
            if ($schedule === null) {
                throw new InvalidStateTransitionException('Data jadwal APBD tidak ditemukan untuk tiket ini.');
            }

            $sanitizedNote = $note !== null ? $this->sanitizer->sanitize($note) : null;

            $locked->update([
                'status' => ReportStatus::IN_PROGRESS,
                'assigned_officer_id' => $freshOfficer->id,
                'is_milestone_overdue' => false,
                'sla_started_at' => $now,
                'sla_cycle_started_at' => $now,
                'sla_deadline_at' => $slaDeadline,
            ]);

            $schedule->update([
                'execution_target_date' => $normalizedTargetDate,
                'updated_at' => $now,
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::EXECUTION_STARTED,
                report: $locked,
                actor: $admin,
                fromStatus: ReportStatus::BUDGET_SCHEDULED,
                toStatus: ReportStatus::IN_PROGRESS,
                note: $sanitizedNote ?? "Eksekusi APBD dimulai dengan target selesai {$normalizedTargetDate}.",
                metadata: [
                    'officer_id' => $freshOfficer->id,
                    'execution_target_date' => $normalizedTargetDate,
                ],
            );

            $recipients = array_merge($this->notifier->getReporterAndWatcherIds($locked), [$freshOfficer->id]);
            $this->notifier->notifyUsers(
                $recipients,
                NotificationType::REPORT_EXECUTION_STARTED,
                $locked,
                "Eksekusi lapangan proyek APBD untuk tiket {$locked->ticket_code} telah dimulai (target {$normalizedTargetDate})."
            );

            return $locked->fresh('budgetSchedule');
        });
    }

    /**
     * TR-07 / TR-13: Field officer uploads AFTER evidence, pauses SLA, and enters 72h Citizen Sign-Off Escrow.
     *
     * @param  list<UploadedFile>  $afterPhotos
     */
    public function submitOfficerEvidence(
        Report $report,
        User $officer,
        string $actionNote,
        array $afterPhotos,
        ?float $deviceLat = null,
        ?float $deviceLng = null,
    ): Report {
        if ($afterPhotos === [] || count($afterPhotos) > 3) {
            throw ValidationException::withMessages([
                'photos' => ['Jumlah foto bukti selesai (After) wajib antara 1 hingga 3 foto.'],
            ]);
        }

        $writtenFiles = [];

        try {
            return DB::transaction(function () use ($report, $officer, $actionNote, $afterPhotos, $deviceLat, $deviceLng, &$writtenFiles): Report {
                $locked = $this->lockReport($report);
                $this->assertAssignedOfficer($officer, $locked);

                if (! $locked->status->isSlaRunning()) {
                    throw new InvalidStateTransitionException(
                        'Bukti penyelesaian hanya dapat diunggah saat tiket berstatus IN_PROGRESS atau REOPENED_BY_AUDIT.'
                    );
                }

                $fromStatus = $locked->status;
                $savedPhotos = $this->geotagValidator->processAfterPhotos(
                    report: $locked,
                    files: $afterPhotos,
                    deviceLat: $deviceLat,
                    deviceLng: $deviceLng,
                    uploadedByUserId: $officer->id,
                    cycle: $locked->current_cycle,
                );

                foreach ($savedPhotos as $savedPhoto) {
                    $writtenFiles[] = $savedPhoto->file_path;
                    $writtenFiles[] = $savedPhoto->thumbnail_path;
                }

                $photoCollection = collect($savedPhotos);
                $warningPhotos = $photoCollection->where('has_geotag_warning', true);
                $hasGeotagWarning = $warningPhotos->isNotEmpty();

                $representativePhoto = $hasGeotagWarning
                    ? ($warningPhotos->sortByDesc(static fn ($photo): int => $photo->distance_from_report_m ?? -1)->first() ?? $savedPhotos[0])
                    : $savedPhotos[0];

                $now = CarbonImmutable::now('UTC');
                $windowHours = (int) config('pastara.signoff_window_hours', 72);
                $exceededDeadline = $locked->sla_deadline_at !== null && $now->gt($locked->sla_deadline_at);
                $isLate = $locked->is_late || $exceededDeadline;
                $sanitizedActionNote = $this->sanitizer->sanitize($actionNote);

                $locked->update([
                    'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
                    'sla_paused_at' => $now,
                    'signoff_started_at' => $now,
                    'signoff_deadline_at' => $now->addHours($windowHours),
                    'signoff_reminder_sent_at' => null,
                    'is_sla_overdue' => $locked->is_sla_overdue || $exceededDeadline,
                    'is_late' => $isLate,
                    'geotag_warning' => $hasGeotagWarning,
                    'geotag_distance_m' => $representativePhoto->distance_from_report_m,
                    'officer_action_note' => $sanitizedActionNote,
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::EVIDENCE_SUBMITTED,
                    report: $locked,
                    actor: $officer,
                    fromStatus: $fromStatus,
                    toStatus: ReportStatus::PENDING_CITIZEN_SIGN_OFF,
                    note: $sanitizedActionNote,
                    metadata: [
                        'cycle' => $locked->current_cycle,
                        'geotag_warning' => $hasGeotagWarning,
                        'geotag_distance_m' => $representativePhoto->distance_from_report_m,
                    ],
                );

                $this->notifier->notifyUsers(
                    $this->notifier->getReporterAndWatcherIds($locked),
                    NotificationType::SIGN_OFF_REQUESTED,
                    $locked,
                    "Petugas mengunggah bukti penyelesaian untuk tiket {$locked->ticket_code}. Mohon konfirmasi dalam {$windowHours} jam."
                );

                return $locked->fresh('photos');
            });
        } catch (Throwable $e) {
            if ($writtenFiles !== []) {
                Storage::disk('public')->delete($writtenFiles);
            }

            throw $e;
        }
    }

    /**
     * TR-08: Citizen approves completion within the 72-hour Escrow sign-off window.
     */
    public function citizenApprove(Report $report, ?User $actor = null, ?string $secretToken = null): Report
    {
        if ($actor !== null) {
            $this->guardUnilateralClose($actor, ReportStatus::VERIFIED_CLOSED);
        }

        return DB::transaction(function () use ($report, $actor, $secretToken): Report {
            $locked = $this->lockReport($report);
            $isReporter = $this->assertCitizenSignOffAuthorized($locked, $actor, $secretToken);
            $this->assertSignOffWindowOpen($locked);

            $now = CarbonImmutable::now('UTC');

            $locked->update([
                'status' => ReportStatus::VERIFIED_CLOSED,
                'is_sla_overdue' => false,
                'sla_stopped_at' => $now,
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::CITIZEN_APPROVED,
                report: $locked,
                actor: $isReporter ? $actor : null,
                fromStatus: ReportStatus::PENDING_CITIZEN_SIGN_OFF,
                toStatus: ReportStatus::VERIFIED_CLOSED,
                note: 'Pelapor mengonfirmasi perbaikan telah tuntas.',
                actorRoleOverride: 'WARGA',
            );

            $recipients = array_merge(
                $this->notifier->getReporterAndWatcherIds($locked),
                $this->notifier->getUnitAdminIds((int) $locked->opd_unit_id),
                $locked->assigned_officer_id !== null ? [$locked->assigned_officer_id] : []
            );

            $this->notifier->notifyUsers(
                $recipients,
                NotificationType::REPORT_CLOSED,
                $locked,
                "Tiket {$locked->ticket_code} telah disetujui tuntas oleh pelapor."
            );

            return $locked->fresh();
        });
    }

    /**
     * TR-09: Citizen rejects/vetoes completion claim with counter-evidence photos, opening a Dispute.
     *
     * @param  list<UploadedFile>  $disputePhotos
     */
    public function citizenReject(
        Report $report,
        ?User $actor,
        ?string $secretToken,
        string $reason,
        array $disputePhotos,
    ): Report {
        if ($disputePhotos === [] || count($disputePhotos) > 3) {
            throw ValidationException::withMessages([
                'photos' => ['Jumlah foto bukti penolakan (veto) wajib antara 1 hingga 3 foto.'],
            ]);
        }

        $writtenFiles = [];

        try {
            return DB::transaction(function () use ($report, $actor, $secretToken, $reason, $disputePhotos, &$writtenFiles): Report {
                $locked = $this->lockReport($report);
                $isReporter = $this->assertCitizenSignOffAuthorized($locked, $actor, $secretToken);
                $this->assertSignOffWindowOpen($locked);

                $sanitizedReason = $this->sanitizer->sanitize($reason);

                foreach (array_values($disputePhotos) as $index => $photoFile) {
                    $photo = $this->geotagValidator->processUpload(
                        report: $locked,
                        file: $photoFile,
                        type: PhotoType::DISPUTE,
                        cycle: $locked->current_cycle,
                        sequence: $index + 1,
                        uploadedByUserId: $isReporter ? $actor?->id : $locked->reporter_id,
                    );
                    $writtenFiles[] = $photo->file_path;
                    $writtenFiles[] = $photo->thumbnail_path;
                }

                Dispute::create([
                    'report_id' => $locked->id,
                    'cycle' => $locked->current_cycle,
                    'raised_by_user_id' => $locked->reporter_id,
                    'citizen_reason' => $sanitizedReason,
                    'status' => DisputeStatus::PENDING,
                ]);

                $locked->update([
                    'status' => ReportStatus::DISPUTED,
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::CITIZEN_REJECTED,
                    report: $locked,
                    actor: $isReporter ? $actor : null,
                    fromStatus: ReportStatus::PENDING_CITIZEN_SIGN_OFF,
                    toStatus: ReportStatus::DISPUTED,
                    note: $sanitizedReason,
                    metadata: ['cycle' => $locked->current_cycle],
                    actorRoleOverride: 'WARGA',
                );

                $recipients = array_merge(
                    $this->notifier->getInspectorateIds(),
                    $this->notifier->getUnitAdminIds((int) $locked->opd_unit_id),
                    $locked->assigned_officer_id !== null ? [$locked->assigned_officer_id] : []
                );

                $this->notifier->notifyUsers(
                    $recipients,
                    NotificationType::DISPUTE_RAISED,
                    $locked,
                    "Pelapor menolak klaim selesai untuk tiket {$locked->ticket_code}. Sengketa masuk sidang Inspektorat."
                );

                return $locked->fresh(['disputes', 'photos']);
            });
        } catch (Throwable $e) {
            if ($writtenFiles !== []) {
                Storage::disk('public')->delete($writtenFiles);
            }

            throw $e;
        }
    }

    /**
     * TR-10: System auto-verifies expired sign-off window after 72 hours.
     */
    public function autoVerifyExpired(Report $report): Report
    {
        return DB::transaction(function () use ($report): Report {
            $locked = $this->lockReport($report);
            $this->assertStatus($locked, ReportStatus::PENDING_CITIZEN_SIGN_OFF);

            $now = CarbonImmutable::now('UTC');
            if ($locked->signoff_deadline_at === null || $now->lt($locked->signoff_deadline_at)) {
                throw new InvalidStateTransitionException('Jendela waktu konfirmasi warga 72 jam belum berakhir.');
            }

            $locked->update([
                'status' => ReportStatus::AUTO_VERIFIED_CLOSED,
                'is_sla_overdue' => false,
                'sla_stopped_at' => $now,
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::AUTO_VERIFIED,
                report: $locked,
                actor: null,
                fromStatus: ReportStatus::PENDING_CITIZEN_SIGN_OFF,
                toStatus: ReportStatus::AUTO_VERIFIED_CLOSED,
                note: 'Ditutup otomatis oleh sistem setelah 72 jam tanpa tanggapan pelapor.',
                actorRoleOverride: 'SYSTEM',
            );

            $recipients = array_merge(
                $this->notifier->getReporterAndWatcherIds($locked),
                $this->notifier->getUnitAdminIds((int) $locked->opd_unit_id),
                $locked->assigned_officer_id !== null ? [$locked->assigned_officer_id] : []
            );

            $this->notifier->notifyUsers(
                $recipients,
                NotificationType::REPORT_CLOSED,
                $locked,
                "Tiket {$locked->ticket_code} ditutup otomatis (72 jam berlalu tanpa penolakan)."
            );

            return $locked->fresh();
        });
    }

    /**
     * TR-11 / TR-12: Inspectorate resolves a pending citizen dispute (REOPENED or CLOSED).
     */
    public function resolveDispute(
        Dispute $dispute,
        User $auditor,
        string $decision,
        string $auditNotes,
        ?int $reopenSlaHours = null,
    ): Report {
        if ($auditor->role !== UserRole::INSPEKTORAT || ! $auditor->is_active) {
            throw new AuthorizationException('Hanya auditor Inspektorat aktif yang berhak memutus sengketa.');
        }

        return DB::transaction(function () use ($dispute, $auditor, $decision, $auditNotes, $reopenSlaHours): Report {
            // Consistent parent-before-child lock order (Report -> Dispute) prevents deadlocks
            $lockedReport = Report::query()->whereKey($dispute->report_id)->lockForUpdate()->firstOrFail();
            $lockedDispute = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedDispute->report_id !== (int) $lockedReport->id || $lockedDispute->status !== DisputeStatus::PENDING || $lockedReport->status !== ReportStatus::DISPUTED) {
                throw new InvalidStateTransitionException('Sengketa sudah diputus atau status tiket tidak lagi DISPUTED.');
            }

            $now = CarbonImmutable::now('UTC');
            $sanitizedNotes = $this->sanitizer->sanitize($auditNotes);

            if ($decision === DisputeStatus::REOPENED->value) {
                if (! in_array($reopenSlaHours, [24, 48, 72], true)) {
                    throw ValidationException::withMessages([
                        'reopen_sla_hours' => ['Tenggat pengerjaan ulang wajib 24, 48, atau 72 jam.'],
                    ]);
                }

                $lockedDispute->update([
                    'status' => DisputeStatus::REOPENED,
                    'auditor_id' => $auditor->id,
                    'audit_notes' => $sanitizedNotes,
                    'reopen_sla_hours' => $reopenSlaHours,
                    'resolved_at' => $now,
                ]);

                $lockedReport->update([
                    'status' => ReportStatus::REOPENED_BY_AUDIT,
                    'current_cycle' => $lockedReport->current_cycle + 1,
                    'is_late' => true,
                    'is_sla_overdue' => false,
                    'sla_warning_notified_at' => null,
                    'sla_paused_at' => null,
                    'sla_cycle_started_at' => $now,
                    'sla_deadline_at' => $now->addHours($reopenSlaHours),
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::AUDIT_REOPENED,
                    report: $lockedReport,
                    actor: $auditor,
                    fromStatus: ReportStatus::DISPUTED,
                    toStatus: ReportStatus::REOPENED_BY_AUDIT,
                    note: $sanitizedNotes,
                    metadata: [
                        'dispute_id' => $lockedDispute->id,
                        'reopen_sla_hours' => $reopenSlaHours,
                        'new_cycle' => $lockedReport->current_cycle,
                    ],
                );

                $recipients = array_merge(
                    [$lockedReport->reporter_id],
                    $this->notifier->getUnitAdminIds((int) $lockedReport->opd_unit_id),
                    $lockedReport->assigned_officer_id !== null ? [$lockedReport->assigned_officer_id] : []
                );

                $this->notifier->notifyUsers(
                    $recipients,
                    NotificationType::AUDIT_DECISION_MADE,
                    $lockedReport,
                    "Inspektorat memutuskan pengerjaan ulang untuk tiket {$lockedReport->ticket_code} (tenggat {$reopenSlaHours} jam)."
                );

                return $lockedReport->fresh(['disputes']);
            }

            if ($decision === DisputeStatus::CLOSED->value) {
                $lockedDispute->update([
                    'status' => DisputeStatus::CLOSED,
                    'auditor_id' => $auditor->id,
                    'audit_notes' => $sanitizedNotes,
                    'reopen_sla_hours' => null,
                    'resolved_at' => $now,
                ]);

                $lockedReport->update([
                    'status' => ReportStatus::CLOSED_BY_AUDIT,
                    'is_sla_overdue' => false,
                    'sla_stopped_at' => $now,
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::AUDIT_CLOSED,
                    report: $lockedReport,
                    actor: $auditor,
                    fromStatus: ReportStatus::DISPUTED,
                    toStatus: ReportStatus::CLOSED_BY_AUDIT,
                    note: $sanitizedNotes,
                    metadata: ['dispute_id' => $lockedDispute->id],
                );

                $recipients = array_merge(
                    $this->notifier->getReporterAndWatcherIds($lockedReport),
                    $this->notifier->getUnitAdminIds((int) $lockedReport->opd_unit_id),
                    $lockedReport->assigned_officer_id !== null ? [$lockedReport->assigned_officer_id] : []
                );

                $this->notifier->notifyUsers(
                    $recipients,
                    NotificationType::AUDIT_DECISION_MADE,
                    $lockedReport,
                    "Inspektorat menutup tiket {$lockedReport->ticket_code} setelah sidang sengketa."
                );

                return $lockedReport->fresh(['disputes']);
            }

            throw ValidationException::withMessages([
                'decision' => ['Putusan audit harus bernilai REOPENED atau CLOSED.'],
            ]);
        });
    }

    private function lockReport(Report $report): Report
    {
        return Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
    }

    private function assertUnitAdmin(User $admin, Report $report): void
    {
        $freshAdmin = User::query()->whereKey($admin->id)->first();

        if (
            $freshAdmin === null
            || $freshAdmin->role !== UserRole::ADMIN_UNIT
            || ! $freshAdmin->is_active
            || (int) $freshAdmin->opd_unit_id !== (int) $report->opd_unit_id
        ) {
            throw new AuthorizationException('Anda tidak berwenang mengelola tiket unit kerja ini.');
        }
    }

    private function assertAssignedOfficer(User $officer, Report $report): void
    {
        $freshOfficer = User::query()->whereKey($officer->id)->first();

        if (
            $freshOfficer === null
            || $freshOfficer->role !== UserRole::PETUGAS
            || ! $freshOfficer->is_active
            || (int) $report->assigned_officer_id !== (int) $freshOfficer->id
        ) {
            throw new AuthorizationException('Anda tidak ditugaskan pada tiket ini.');
        }
    }

    private function assertOfficerEligibleForUnit(User $officer, int $opdUnitId): void
    {
        if (
            $officer->role !== UserRole::PETUGAS
            || ! $officer->is_active
            || (int) $officer->opd_unit_id !== $opdUnitId
        ) {
            throw ValidationException::withMessages([
                'officer_id' => ['Petugas harus berstatus aktif dan berasal dari unit kerja yang sama.'],
            ]);
        }
    }

    private function assertStatus(Report $report, ReportStatus $expected): void
    {
        if ($report->status !== $expected) {
            throw new InvalidStateTransitionException(
                "Transisi tidak diizinkan: status saat ini {$report->status->value}, diharapkan {$expected->value}."
            );
        }
    }

    private function assertCitizenSignOffAuthorized(Report $report, ?User $actor, ?string $secretToken): bool
    {
        if ($actor !== null && $actor->is_active && $actor->role === UserRole::WARGA && (int) $actor->id === (int) $report->reporter_id) {
            return true;
        }

        if ($report->is_secret && $report->verifySecretToken($secretToken)) {
            return false;
        }

        throw new AuthorizationException('Anda tidak memiliki izin untuk melakukan sign-off pada tiket ini.');
    }

    private function assertSignOffWindowOpen(Report $report): void
    {
        $this->assertStatus($report, ReportStatus::PENDING_CITIZEN_SIGN_OFF);

        if ($report->signoff_deadline_at === null || CarbonImmutable::now('UTC')->gt($report->signoff_deadline_at)) {
            throw new InvalidStateTransitionException('Jendela waktu konfirmasi warga 72 jam telah berakhir.');
        }
    }

    private function resolveTargetUnit(Category $category, Village $village): OpdUnit
    {
        if ($category->route_by_village) {
            if ($village->opdUnit !== null && $village->opdUnit->is_active) {
                return $village->opdUnit;
            }

            return OpdUnit::query()->where('code', 'DESA_UMUM')->where('is_active', true)->firstOrFail();
        }

        $unit = OpdUnit::query()->whereKey($category->default_opd_unit_id)->where('is_active', true)->first();
        if ($unit === null) {
            throw ValidationException::withMessages([
                'category_id' => ['Unit kerja default kategori sedang tidak aktif.'],
            ]);
        }

        return $unit;
    }

    private function generateUniqueTicketCode(): string
    {
        do {
            $suffix = $this->randomTokenSegment(8);
            $code = "PST-{$suffix}";
        } while (Report::query()->where('ticket_code', $code)->exists());

        return $code;
    }

    private function generateSecretToken(): string
    {
        return sprintf('TKN-%s-%s', $this->randomTokenSegment(4), $this->randomTokenSegment(4));
    }

    private function randomTokenSegment(int $length): string
    {
        $maxIndex = strlen(self::TOKEN_ALPHABET) - 1;
        $segment = '';

        for ($i = 0; $i < $length; $i++) {
            $segment .= self::TOKEN_ALPHABET[random_int(0, $maxIndex)];
        }

        return $segment;
    }

    public function storeApbdDocument(UploadedFile $file, string $field = 'document_proof'): string
    {
        $realPath = $file->getRealPath() ?: $file->getPathname();
        $rawContents = $realPath !== '' ? @file_get_contents($realPath) : false;

        if ($rawContents === false || $rawContents === '') {
            throw ValidationException::withMessages([
                $field => ['Berkas dokumen bukti usulan APBD tidak dapat dibaca atau kosong.'],
            ]);
        }

        $detectedMime = strtolower((string) $file->getMimeType());
        $now = CarbonImmutable::now('UTC');

        if ($detectedMime === 'application/pdf') {
            if (! str_starts_with($rawContents, '%PDF-')) {
                throw ValidationException::withMessages([
                    $field => ['Berkas PDF tidak valid atau rusak.'],
                ]);
            }

            $path = sprintf('documents/%s/%s/%s.pdf', $now->format('Y'), $now->format('m'), Str::uuid());
            if (! Storage::disk('public')->put($path, $rawContents)) {
                throw new RuntimeException('Gagal menyimpan dokumen bukti APBD ke penyimpanan.');
            }

            return $path;
        }

        if (in_array($detectedMime, ['image/jpeg', 'image/png'], true)) {
            try {
                $imageManager = new ImageManager(Driver::class);
                $image = $imageManager->decodePath($realPath);
                $image->orient();
                $image->scaleDown(width: 1920, height: 1920);
                $encoded = (string) $image->encode(new WebpEncoder(quality: 85, strip: true));
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    $field => ['Berkas gambar dokumen bukti APBD rusak atau tidak dapat diproses.'],
                ]);
            }

            $path = sprintf('documents/%s/%s/%s.webp', $now->format('Y'), $now->format('m'), Str::uuid());

            if (! Storage::disk('public')->put($path, $encoded)) {
                throw new RuntimeException('Gagal menyimpan dokumen gambar bukti APBD ke penyimpanan.');
            }

            return $path;
        }

        throw ValidationException::withMessages([
            $field => ['Dokumen bukti usulan APBD hanya boleh berupa PDF, JPG, atau PNG yang valid.'],
        ]);
    }
}
