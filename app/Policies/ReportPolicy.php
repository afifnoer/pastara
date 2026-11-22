<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReportPolicy
{
    public function viewPublic(?User $user, Report $report): Response
    {
        return ! $report->is_secret
            ? Response::allow()
            : Response::denyAsNotFound('Data tidak ditemukan.');
    }

    public function viewCitizen(User $user, Report $report): Response
    {
        if (! $user->is_active || $user->role !== UserRole::WARGA) {
            return Response::deny('Hanya akun warga aktif yang dapat mengakses tampilan warga.');
        }

        $isOwner = (int) $user->id === (int) $report->reporter_id;

        return ($isOwner || ! $report->is_secret)
            ? Response::allow()
            : Response::denyAsNotFound('Data tidak ditemukan.');
    }

    public function viewUnit(User $user, Report $report): Response
    {
        if ($user->role !== UserRole::ADMIN_UNIT) {
            return Response::deny('Anda tidak memiliki izin untuk mengakses kotak masuk unit.');
        }

        return (int) $user->opd_unit_id === (int) $report->opd_unit_id
            ? Response::allow()
            : Response::denyAsNotFound('Data tidak ditemukan.');
    }

    public function manageUnit(User $user, Report $report): Response
    {
        $unitCheck = $this->viewUnit($user, $report);
        if ($unitCheck->denied()) {
            return $unitCheck;
        }

        return $report->status === ReportStatus::ROUTED_TO_UNIT
            ? Response::allow()
            : Response::denyWithStatus(409, 'Status laporan saat ini tidak mengizinkan aksi verifikasi awal.');
    }

    public function manageApbd(User $user, Report $report): Response
    {
        $unitCheck = $this->viewUnit($user, $report);
        if ($unitCheck->denied()) {
            return $unitCheck;
        }

        return $report->status === ReportStatus::BUDGET_SCHEDULED
            ? Response::allow()
            : Response::denyWithStatus(409, 'Tiket tidak berada pada status Terjadwal Anggaran (APBD).');
    }

    public function reassignOfficer(User $user, Report $report): Response
    {
        $unitCheck = $this->viewUnit($user, $report);
        if ($unitCheck->denied()) {
            return $unitCheck;
        }

        return $report->status->isSlaRunning()
            ? Response::allow()
            : Response::denyWithStatus(409, 'Petugas hanya dapat diganti saat tiket sedang dikerjakan.');
    }

    public function viewOfficerTask(User $user, Report $report): Response
    {
        if ($user->role !== UserRole::PETUGAS) {
            return Response::deny('Anda tidak memiliki izin untuk mengakses tugas petugas.');
        }

        return (int) $report->assigned_officer_id === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound('Data tidak ditemukan.');
    }

    public function submitEvidence(User $user, Report $report): Response
    {
        $taskCheck = $this->viewOfficerTask($user, $report);
        if ($taskCheck->denied()) {
            return $taskCheck;
        }

        return $report->status->isSlaRunning()
            ? Response::allow()
            : Response::denyWithStatus(409, 'Bukti atau progres hanya dapat diunggah saat tugas sedang berjalan.');
    }

    public function upvote(User $user, Report $report): Response
    {
        $allowed = $user->is_active
            && $user->role === UserRole::WARGA
            && ! $report->is_secret
            && (int) $report->reporter_id !== (int) $user->id
            && ! $report->status->isFinal();

        return $allowed
            ? Response::allow()
            : Response::deny('Anda tidak diizinkan memberi dukungan pada laporan ini.');
    }

    /**
     * Authorize citizen sign-off (owner Bearer or secret_token).
     * Note: For internal-role approve attempts, controllers MUST invoke
     * ReportStateMachineService::guardUnilateralClose() first so Hukum 1
     * emits ESCROW_LOCK_VIOLATION rather than generic FORBIDDEN.
     */
    public function signOff(?User $user, Report $report, ?string $secretToken = null): Response
    {
        if (! $this->isSignOffActorAuthorized($user, $report, $secretToken)) {
            return Response::deny('Anda tidak memiliki izin untuk melakukan sign-off pada laporan ini.');
        }

        $withinWindow = $report->status === ReportStatus::PENDING_CITIZEN_SIGN_OFF
            && $report->signoff_deadline_at !== null
            && now('UTC')->lte($report->signoff_deadline_at);

        return $withinWindow
            ? Response::allow()
            : Response::denyWithStatus(409, 'Laporan tidak berada pada masa konfirmasi atau batas waktu 72 jam telah berakhir.');
    }

    public function rate(?User $user, Report $report, ?string $secretToken = null): Response
    {
        if (! $this->isSignOffActorAuthorized($user, $report, $secretToken)) {
            return Response::deny('Anda tidak memiliki izin untuk memberi penilaian pada laporan ini.');
        }

        return $report->status === ReportStatus::VERIFIED_CLOSED
            ? Response::allow()
            : Response::denyWithStatus(409, 'Penilaian hanya dapat diberikan pada laporan yang telah dikonfirmasi tuntas oleh warga.');
    }

    private function isSignOffActorAuthorized(?User $user, Report $report, ?string $secretToken): bool
    {
        if ($user !== null && $user->is_active && $user->role === UserRole::WARGA && (int) $user->id === (int) $report->reporter_id) {
            return true;
        }

        return $report->is_secret && $report->verifySecretToken($secretToken);
    }
}
