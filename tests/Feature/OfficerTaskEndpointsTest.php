<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Enums\SlaZone;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
    CarbonImmutable::setTestNow();
});

function makeOfficerExifJpeg(float $lat, float $lng, string $dateTimeWib, string $name = 'after.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(100, 80);
    $bg = imagecolorallocate($img, 40, 140, 90);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $raw = (string) ob_get_clean();
    imagedestroy($img);

    $toDms = static function (float $coord): array {
        $abs = abs($coord);
        $deg = (int) floor($abs);
        $minFloat = ($abs - $deg) * 60.0;
        $min = (int) floor($minFloat);
        $sec = (int) round(($minFloat - $min) * 60.0 * 100);

        return [[$deg, 1], [$min, 1], [$sec, 100]];
    };

    $packRationals = static function (array $rats): string {
        $bin = '';
        foreach ($rats as [$n, $d]) {
            $bin .= pack('NN', $n, $d);
        }

        return $bin;
    };

    $dateBytes = $dateTimeWib."\0";
    $ifd0Offset = 8;
    $exifIfdOffset = $ifd0Offset + 2 + (2 * 12) + 4;
    $gpsIfdOffset = $exifIfdOffset + 2 + 12 + 4;
    $dataOffset = $gpsIfdOffset + 2 + (4 * 12) + 4;

    $dateDataOffset = $dataOffset;
    $latDataOffset = $dateDataOffset + strlen($dateBytes);
    $lngDataOffset = $latDataOffset + 24;

    $ifd0 = pack('n', 2)
        .pack('nnNN', 0x8769, 4, 1, $exifIfdOffset)
        .pack('nnNN', 0x8825, 4, 1, $gpsIfdOffset)
        .pack('N', 0);

    $exifIfd = pack('n', 1)
        .pack('nnNN', 0x9003, 2, strlen($dateBytes), $dateDataOffset)
        .pack('N', 0);

    $latRef = ($lat < 0 ? 'S' : 'N')."\0\0\0";
    $lngRef = ($lng < 0 ? 'W' : 'E')."\0\0\0";

    $gpsIfd = pack('n', 4)
        .pack('nnN', 0x0001, 2, 2).$latRef
        .pack('nnNN', 0x0002, 5, 3, $latDataOffset)
        .pack('nnN', 0x0003, 2, 2).$lngRef
        .pack('nnNN', 0x0004, 5, 3, $lngDataOffset)
        .pack('N', 0);

    $tiff = "MM\x00\x2A".pack('N', $ifd0Offset)
        .$ifd0.$exifIfd.$gpsIfd
        .$dateBytes.$packRationals($toDms($lat)).$packRationals($toDms($lng));

    $app1Body = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($app1Body) + 2).$app1Body;

    return UploadedFile::fake()->createWithContent($name, substr($raw, 0, 2).$app1.substr($raw, 2));
}

it('lists only officer-owned active/history tasks sorted by nearest SLA deadline and records progress without pausing SLA (TASK-BE-042, AC-040, AC-041)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $officerDlh = User::where('email', 'petugas.dlh@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();

    // Non-PETUGAS rejected with 403
    $this->actingAs($adminDpupr, 'sanctum')
        ->getJson('/api/v1/officer/tasks')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // Active tasks belong exclusively to logged-in officer and are ordered by sla_deadline_at ASC
    $activeResponse = $this->actingAs($officerDlh, 'sanctum')
        ->getJson('/api/v1/officer/tasks?status=active');

    $activeResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $activeTasks = collect($activeResponse->json('data'));
    expect($activeTasks->isNotEmpty())->toBeTrue();

    $deadlines = [];
    foreach ($activeTasks as $task) {
        expect($task['assigned_officer']['id'])->toBe($officerDlh->id)
            ->and(in_array($task['status'], [ReportStatus::IN_PROGRESS->value, ReportStatus::REOPENED_BY_AUDIT->value], true))->toBeTrue();
        if ($task['sla']['sla_deadline_at'] !== null) {
            $deadlines[] = $task['sla']['sla_deadline_at'];
        }
    }
    $sortedDeadlines = $deadlines;
    sort($sortedDeadlines);
    expect($deadlines)->toBe($sortedDeadlines);

    // History tab lists non-running tasks owned by officer
    $historyResponse = $this->actingAs($officerDishub, 'sanctum')
        ->getJson('/api/v1/officer/tasks?status=history');
    $historyResponse->assertStatus(200);
    expect(collect($historyResponse->json('data'))->pluck('ticket_code')->all())->toContain('PST-ESCROW01');

    // Cross-officer isolation: Dishub officer cannot post progress on DLH officer's PST-DUPL0001 (404)
    $dupl = Report::where('ticket_code', 'PST-DUPL0001')->firstOrFail();
    $originalDeadline = $dupl->sla_deadline_at?->toIso8601ZuluString();

    $this->actingAs($officerDishub, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$dupl->id}/progress", [
            'note' => 'Mencoba mengirim catatan pada tugas petugas lain harus 404.',
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // Valid progress update with photo does NOT pause SLA or alter status/deadline
    $progressPhoto = makeOfficerExifJpeg((float) $dupl->latitude, (float) $dupl->longitude, '2026:11:22 10:00:00', 'progress.jpg');
    $progressResponse = $this->actingAs($officerDlh, 'sanctum')
        ->post("/api/v1/officer/tasks/{$dupl->id}/progress", [
            'note' => 'Armada truk sampah tiba di lokasi dan mulai proses pengangkutan tahap pertama.',
            'photos' => [$progressPhoto],
        ], ['Accept' => 'application/json']);

    $progressResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::IN_PROGRESS->value)
        ->assertJsonPath('data.sla.sla_paused_at', null)
        ->assertJsonPath('data.sla.sla_deadline_at', $originalDeadline);

    expect($dupl->photos()->where('photo_type', PhotoType::PROGRESS->value)->count())->toBe(1)
        ->and($dupl->auditLogs()->where('action_type', AuditActionType::PROGRESS_NOTE)->exists())->toBeTrue();
});

it('submits AFTER evidence photos within and outside geotag tolerance and locks report into PENDING_CITIZEN_SIGN_OFF Escrow (TASK-BE-043, AC-042, AC-043)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    // Fix server clock to 2026-11-22 06:00:00 UTC (13:00:00 WIB)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));

    $officerDlh = User::where('email', 'petugas.dlh@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();

    // 1. Within geotag tolerance (~20 m, 12:00 WIB = 1h ago) -> geotag_warning = false, status = PENDING_CITIZEN_SIGN_OFF
    $dupl = Report::where('ticket_code', 'PST-DUPL0001')->firstOrFail();
    $withinTolerancePhoto = makeOfficerExifJpeg(
        (float) $dupl->latitude + 0.00015,
        (float) $dupl->longitude,
        '2026:11:22 12:00:00',
        'after-valid.jpg'
    );

    $okResponse = $this->actingAs($officerDlh, 'sanctum')
        ->post("/api/v1/officer/tasks/{$dupl->id}/evidence", [
            'action_note' => 'Seluruh tumpukan sampah di pinggir jalan Jeruklegi telah diangkut dan dibersihkan tuntas.',
            'photos' => [$withinTolerancePhoto],
        ], ['Accept' => 'application/json']);

    $okResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.sla.sla_zone', SlaZone::PAUSED->value)
        ->assertJsonPath('data.geotag.geotag_warning', false);

    expect($okResponse->json('data.signoff.signoff_deadline_at'))->not->toBeNull()
        ->and($okResponse->json('data.geotag.geotag_distance_m'))->toBeLessThanOrEqual($dupl->geotag_tolerance_snapshot_m);

    // Re-submitting evidence after entering PENDING_CITIZEN_SIGN_OFF returns 409 Conflict
    $this->actingAs($officerDlh, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$dupl->id}/evidence", [
            'action_note' => 'Mencoba unggah ulang bukti setelah masuk jendela konfirmasi warga.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // 2. Outside geotag tolerance (~850 m away) -> still transitions to PENDING_CITIZEN_SIGN_OFF WITH geotag_warning = true
    $dpuprTask = Report::where('assigned_officer_id', $officerDpupr->id)
        ->whereIn('status', [ReportStatus::IN_PROGRESS->value, ReportStatus::REOPENED_BY_AUDIT->value])
        ->firstOrFail();

    $farPhoto = makeOfficerExifJpeg(
        (float) $dpuprTask->latitude + 0.0080,
        (float) $dpuprTask->longitude,
        '2026:11:22 12:00:00',
        'after-far.jpg'
    );

    $warnResponse = $this->actingAs($officerDpupr, 'sanctum')
        ->post("/api/v1/officer/tasks/{$dpuprTask->id}/evidence", [
            'action_note' => 'Perbaikan struktur perkerasan jalan telah selesai dilaksanakan dan siap diperiksa warga.',
            'photos' => [$farPhoto],
        ], ['Accept' => 'application/json']);

    $warnResponse->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.geotag.geotag_warning', true);

    expect($warnResponse->json('data.geotag.geotag_distance_m'))
        ->toBeGreaterThan($dpuprTask->geotag_tolerance_snapshot_m);
});
