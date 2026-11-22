<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\IssueType;
use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BudgetMilestone;
use App\Models\BudgetSchedule;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\District;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Rating;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\ReportUpvote;
use App\Models\User;
use App\Models\Village;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class CilacapSimulationSeeder extends Seeder
{
    /** @var array<string, OpdUnit> */
    private array $units = [];

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, District> */
    private array $districts = [];

    /** @var array<string, Village> */
    private array $villages = [];

    /** @var array<string, User> */
    private array $users = [];

    /** @var list<User> */
    private array $simCitizens = [];

    /** @var array<string, array{file: string, thumb: string}> */
    private array $dummyMedia = [];

    public function run(): void
    {
        $this->ensureDummyMediaFiles();
        $this->seedOpdUnits();
        $this->seedCategories();
        $this->seedLocations();
        $this->seedUsers();
        $this->seedHeroTickets();
        $this->seedBackgroundTickets();
    }

    private function ensureDummyMediaFiles(): void
    {
        $types = [
            'before' => [120, 90, 75],
            'after' => [34, 139, 94],
            'progress' => [59, 130, 246],
            'dispute' => [220, 38, 38],
        ];

        $disk = Storage::disk('public');

        foreach ($types as $key => [$r, $g, $b]) {
            $filePath = "reports/2026/11/seed-{$key}.webp";
            $thumbPath = "reports/2026/11/seed-{$key}-thumb.webp";

            if (! $disk->exists($filePath)) {
                $disk->put($filePath, $this->renderDummyWebp(800, 600, $r, $g, $b));
            }
            if (! $disk->exists($thumbPath)) {
                $disk->put($thumbPath, $this->renderDummyWebp(480, 360, $r, $g, $b));
            }

            $this->dummyMedia[$key] = [
                'file' => $filePath,
                'thumb' => $thumbPath,
            ];
        }
    }

    private function renderDummyWebp(int $width, int $height, int $r, int $g, int $b): string
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
            $img = imagecreatetruecolor($width, $height);
            $color = imagecolorallocate($img, $r, $g, $b);
            imagefill($img, 0, 0, $color);

            ob_start();
            imagewebp($img, null, 80);
            $bytes = (string) ob_get_clean();
            imagedestroy($img);

            if ($bytes !== '') {
                return $bytes;
            }
        }

        // Minimal 1x1 lossless WebP fallback if GD imagewebp is unavailable.
        return base64_decode('UklGRh4AAABXRUJQVlA4TBEAAAAvAAAAAAfQ//73v/+BiOh/AAA=') ?: '';
    }

    private function seedOpdUnits(): void
    {
        $items = [
            [
                'code' => 'DPUPR',
                'name' => 'Dinas Pekerjaan Umum dan Penataan Ruang',
                'level' => UnitLevel::OPD,
                'description' => 'Penanganan jalan kabupaten, jembatan, saluran drainase, dan pengendalian banjir wilayah.',
            ],
            [
                'code' => 'DISHUB',
                'name' => 'Dinas Perhubungan',
                'level' => UnitLevel::OPD,
                'description' => 'Penerangan jalan umum (PJU), rambu lalu lintas, marka jalan, dan APILL.',
            ],
            [
                'code' => 'DLH',
                'name' => 'Dinas Lingkungan Hidup',
                'level' => UnitLevel::OPD,
                'description' => 'Pengelolaan sampah liar, TPS, ruang terbuka hijau, dan penanganan pohon tumbang.',
            ],
            [
                'code' => 'DISDIKBUD',
                'name' => 'Dinas Pendidikan dan Kebudayaan',
                'level' => UnitLevel::OPD,
                'description' => 'Sarana prasarana dan layanan satuan pendidikan dasar serta kebudayaan daerah.',
            ],
            [
                'code' => 'INSPEKTORAT',
                'name' => 'Inspektorat Daerah Kabupaten Cilacap',
                'level' => UnitLevel::INSPEKTORAT,
                'description' => 'Pengawasan internal, penanganan aduan maladministrasi/pungli, dan sidang sengketa warga.',
            ],
            [
                'code' => 'DESA_SADABUMI',
                'name' => 'Pemerintah Desa Sadabumi (Kec. Majenang)',
                'level' => UnitLevel::DESA,
                'description' => 'Pemerintah Desa Sadabumi untuk penanganan fasilitas lingkungan tingkat desa.',
            ],
            [
                'code' => 'DESA_PURWODADI',
                'name' => 'Pemerintah Desa Purwodadi (Kec. Patimuan)',
                'level' => UnitLevel::DESA,
                'description' => 'Pemerintah Desa Purwodadi untuk penanganan fasilitas lingkungan tingkat desa.',
            ],
            [
                'code' => 'DESA_UMUM',
                'name' => 'Pemerintah Desa/Kelurahan Terpadu (Fallback Wilayah)',
                'level' => UnitLevel::DESA,
                'description' => 'Unit koordinasi terpadu untuk desa/kelurahan yang belum memiliki admin mandiri.',
            ],
        ];

        foreach ($items as $item) {
            $unit = OpdUnit::create($item + ['is_active' => true]);
            $this->units[$unit->code] = $unit;
        }
    }

    private function seedCategories(): void
    {
        $items = [
            [
                'code' => 'CAT-01',
                'name' => 'Jalan & Jembatan Rusak',
                'description' => 'Jalan berlubang, amblas, jembatan rusak, dan kerusakan konstruksi ruas jalan.',
                'default_opd_unit_id' => $this->units['DPUPR']->id,
                'route_by_village' => false,
                'default_sla_hours' => 72,
                'issue_type' => IssueType::LINEAR,
                'duplicate_radius_m' => 750,
                'geotag_tolerance_m' => 500,
                'recommend_secret' => false,
            ],
            [
                'code' => 'CAT-02',
                'name' => 'Lampu Jalan, Rambu & Lalu Lintas',
                'description' => 'Lampu PJU padam, tiang miring, rambu rusak, dan lampu lalu lintas bermasalah.',
                'default_opd_unit_id' => $this->units['DISHUB']->id,
                'route_by_village' => false,
                'default_sla_hours' => 24,
                'issue_type' => IssueType::POINT,
                'duplicate_radius_m' => 200,
                'geotag_tolerance_m' => 100,
                'recommend_secret' => false,
            ],
            [
                'code' => 'CAT-03',
                'name' => 'Sampah & Pohon',
                'description' => 'Tumpukan sampah liar, TPS meluber, dahan membahayakan, dan pohon tumbang.',
                'default_opd_unit_id' => $this->units['DLH']->id,
                'route_by_village' => false,
                'default_sla_hours' => 48,
                'issue_type' => IssueType::POINT,
                'duplicate_radius_m' => 200,
                'geotag_tolerance_m' => 100,
                'recommend_secret' => false,
            ],
            [
                'code' => 'CAT-04',
                'name' => 'Saluran/Drainase & Banjir Lingkungan',
                'description' => 'Drainase tersumbat, gorong-gorong pecah, dan genangan banjir pada ruas jalan.',
                'default_opd_unit_id' => $this->units['DPUPR']->id,
                'route_by_village' => false,
                'default_sla_hours' => 72,
                'issue_type' => IssueType::LINEAR,
                'duplicate_radius_m' => 750,
                'geotag_tolerance_m' => 500,
                'recommend_secret' => false,
            ],
            [
                'code' => 'CAT-05',
                'name' => 'Fasilitas Lingkungan RT/RW (gang, got kecil)',
                'description' => 'Jalan lingkungan/gang, saluran RT/RW, dan fasilitas umum skala desa/kelurahan.',
                'default_opd_unit_id' => null,
                'route_by_village' => true,
                'default_sla_hours' => 72,
                'issue_type' => IssueType::POINT,
                'duplicate_radius_m' => 200,
                'geotag_tolerance_m' => 100,
                'recommend_secret' => false,
            ],
            [
                'code' => 'CAT-06',
                'name' => 'Sekolah & Pendidikan',
                'description' => 'Keamanan lingkungan sekolah, ruang kelas rusak, dan layanan pendidikan dasar.',
                'default_opd_unit_id' => $this->units['DISDIKBUD']->id,
                'route_by_village' => false,
                'default_sla_hours' => 72,
                'issue_type' => IssueType::POINT,
                'duplicate_radius_m' => 200,
                'geotag_tolerance_m' => 100,
                'recommend_secret' => true,
            ],
            [
                'code' => 'CAT-07',
                'name' => 'Pungli / Maladministrasi Layanan',
                'description' => 'Indikasi pungutan liar, penundaan berlarut, atau penyalahgunaan wewenang layanan.',
                'default_opd_unit_id' => $this->units['INSPEKTORAT']->id,
                'route_by_village' => false,
                'default_sla_hours' => 48,
                'issue_type' => IssueType::POINT,
                'duplicate_radius_m' => 200,
                'geotag_tolerance_m' => 100,
                'recommend_secret' => true,
            ],
        ];

        foreach ($items as $item) {
            $cat = Category::create($item + ['is_active' => true]);
            $this->categories[$cat->code] = $cat;
        }
    }

    private function seedLocations(): void
    {
        $districtList = [
            ['33.01.01', 'Kedungreja', [['Kedungreja', 'DESA', null], ['Tambaksari', 'DESA', null]]],
            ['33.01.02', 'Kesugihan', [['Kesugihan', 'DESA', null], ['Planjan', 'DESA', null]]],
            ['33.01.03', 'Adipala', [['Adipala', 'DESA', null], ['Bunton', 'DESA', null]]],
            ['33.01.04', 'Binangun', [['Binangun', 'DESA', null], ['Widara Payung', 'DESA', null]]],
            ['33.01.05', 'Nusawungu', [['Nusawungu', 'DESA', null], ['Jetis', 'DESA', null]]],
            ['33.01.06', 'Kroya', [['Kroya', 'DESA', null], ['Bajing', 'DESA', null]]],
            ['33.01.07', 'Maos', [['Maos Kidul', 'DESA', null], ['Maos Lor', 'DESA', null]]],
            ['33.01.08', 'Jeruklegi', [['Jeruklegi Kulon', 'DESA', null], ['Jeruklegi Wetan', 'DESA', null]]],
            ['33.01.09', 'Kawunganten', [['Kawunganten', 'DESA', null], ['Bojong', 'DESA', null]]],
            ['33.01.10', 'Gandrungmangu', [['Gandrungmangu', 'DESA', null], ['Sidaurip', 'DESA', null]]],
            ['33.01.11', 'Sidareja', [['Sidareja', 'DESA', null], ['Tinggarjaya', 'DESA', null]]],
            ['33.01.12', 'Karangpucung', [['Karangpucung', 'DESA', null], ['Ciporos', 'DESA', null]]],
            ['33.01.13', 'Cimanggu', [['Cimanggu', 'DESA', null], ['Cibalung', 'DESA', null]]],
            ['33.01.14', 'Majenang', [['Sadabumi', 'DESA', 'DESA_SADABUMI'], ['Ujungbarang', 'DESA', null], ['Jenang', 'DESA', null]]],
            ['33.01.15', 'Wanareja', [['Wanareja', 'DESA', null], ['Bantar', 'DESA', null]]],
            ['33.01.16', 'Dayeuhluhur', [['Dayeuhluhur', 'DESA', null], ['Matenggeng', 'DESA', null]]],
            ['33.01.17', 'Sampang', [['Sampang', 'DESA', null], ['Karangtengah', 'DESA', null]]],
            ['33.01.18', 'Cipari', [['Cipari', 'DESA', null], ['Segaralangu', 'DESA', null]]],
            ['33.01.19', 'Patimuan', [['Purwodadi', 'DESA', 'DESA_PURWODADI'], ['Patimuan', 'DESA', null]]],
            ['33.01.20', 'Bantarsari', [['Bantarsari', 'DESA', null], ['Kamulyan', 'DESA', null]]],
            ['33.01.21', 'Cilacap Selatan', [['Tambakreja', 'KELURAHAN', null], ['Sidakaya', 'KELURAHAN', null]]],
            ['33.01.22', 'Cilacap Tengah', [['Sidanegara', 'KELURAHAN', null], ['Donan', 'KELURAHAN', null], ['Gunungsimping', 'KELURAHAN', null]]],
            ['33.01.23', 'Cilacap Utara', [['Gumilir', 'KELURAHAN', null], ['Tritih Kulon', 'KELURAHAN', null]]],
            ['33.01.24', 'Kampung Laut', [['Ujung Alang', 'DESA', null], ['Klaces', 'DESA', null]]],
        ];

        foreach ($districtList as [$code, $name, $villages]) {
            $district = District::create(['code' => $code, 'name' => $name]);
            $this->districts[$name] = $district;

            foreach ($villages as [$vName, $vType, $unitCode]) {
                $village = Village::create([
                    'district_id' => $district->id,
                    'opd_unit_id' => $unitCode !== null ? $this->units[$unitCode]->id : null,
                    'name' => $vName,
                    'type' => $vType,
                ]);
                $this->villages["{$name}:{$vName}"] = $village;
            }
        }
    }

    private function seedUsers(): void
    {
        $password = Hash::make('Pastara2027!');

        $accounts = [
            ['Warga Pelapor Uji', 'warga.demo@pastara.id', UserRole::WARGA, null, '081200000001'],
            ['Admin DPUPR Cilacap', 'admin.dpupr@pastara.id', UserRole::ADMIN_UNIT, 'DPUPR', '081200000002'],
            ['Admin Dishub Cilacap', 'admin.dishub@pastara.id', UserRole::ADMIN_UNIT, 'DISHUB', '081200000003'],
            ['Admin DLH Cilacap', 'admin.dlh@pastara.id', UserRole::ADMIN_UNIT, 'DLH', '081200000004'],
            ['Admin Disdikbud Cilacap', 'admin.disdikbud@pastara.id', UserRole::ADMIN_UNIT, 'DISDIKBUD', '081200000005'],
            ['Admin Desa Sadabumi', 'admin.sadabumi@pastara.id', UserRole::ADMIN_UNIT, 'DESA_SADABUMI', '081200000006'],
            ['Petugas Teknis DPUPR', 'petugas.dpupr@pastara.id', UserRole::PETUGAS, 'DPUPR', '081200000007'],
            ['Petugas PJU Dishub', 'petugas.dishub@pastara.id', UserRole::PETUGAS, 'DISHUB', '081200000008'],
            ['Petugas Kebersihan DLH', 'petugas.dlh@pastara.id', UserRole::PETUGAS, 'DLH', '081200000009'],
            ['Auditor Inspektorat Daerah', 'auditor.inspektorat@pastara.id', UserRole::INSPEKTORAT, 'INSPEKTORAT', '081200000010'],
            ['Super Admin Pastara', 'superadmin@pastara.id', UserRole::SUPER_ADMIN, null, '081200000011'],
        ];

        foreach ($accounts as [$name, $email, $role, $unitCode, $phone]) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'phone' => $phone,
                'role' => $role,
                'opd_unit_id' => $unitCode !== null ? $this->units[$unitCode]->id : null,
                'is_active' => true,
            ]);
            $this->users[$email] = $user;
        }

        // Anonymized citizen accounts for realistic upvote/rating distributions.
        for ($i = 1; $i <= 15; $i++) {
            $num = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->simCitizens[] = User::create([
                'name' => "Warga Cilacap {$num}",
                'email' => "warga.sim{$num}@pastara.id",
                'password' => $password,
                'phone' => null,
                'role' => UserRole::WARGA,
                'opd_unit_id' => null,
                'is_active' => true,
            ]);
        }
    }

    private function seedHeroTickets(): void
    {
        $now = now('UTC');
        $wargaDemo = $this->users['warga.demo@pastara.id'];

        // 1. PST-SADA2027 — Jalur 2 APBD (Sadabumi)
        $cat01 = $this->categories['CAT-01'];
        $sada = Report::create([
            'ticket_code' => 'PST-SADA2027',
            'reporter_id' => $wargaDemo->id,
            'category_id' => $cat01->id,
            'opd_unit_id' => $this->units['DPUPR']->id,
            'district_id' => $this->districts['Majenang']->id,
            'village_id' => $this->villages['Majenang:Sadabumi']->id,
            'title' => 'Kerusakan Ruas Jl. Sadabumi–Ujungbarang, Kec. Majenang',
            'description_sanitized' => 'Aspal ruas jalan kabupaten antara Desa Sadabumi menuju Ujungbarang mengelupas berat sepanjang 450 meter dan membahayakan kendaraan warga.',
            'latitude' => -7.2915000,
            'longitude' => 108.7420000,
            'address_detail' => 'Ruas Jl. Sadabumi KM 3, dekat jembatan sungai',
            'is_secret' => false,
            'resolution_track' => ResolutionTrack::APBD,
            'status' => ReportStatus::BUDGET_SCHEDULED,
            'current_cycle' => 1,
            'issue_type_snapshot' => $cat01->issue_type,
            'duplicate_radius_snapshot_m' => $cat01->duplicate_radius_m,
            'geotag_tolerance_snapshot_m' => $cat01->geotag_tolerance_m,
            'sla_hours_snapshot' => $cat01->default_sla_hours,
            'upvote_count' => 12,
            'created_at' => $now->subDays(18),
            'updated_at' => $now->subDays(5),
        ]);
        $this->attachPhoto($sada, $wargaDemo, PhotoType::BEFORE, 1, 1);
        $this->seedUpvotes($sada, 12);

        $schedule = BudgetSchedule::create([
            'report_id' => $sada->id,
            'fiscal_year' => 2027,
            'proposal_ref_number' => 'RKA-DPUPR-2027-014',
            'target_quarter' => 'Q2',
            'estimated_budget' => 850000000.00,
            'document_proof_path' => 'documents/2026/11/rka-dpupr-sadabumi-2027.pdf',
            'document_due_at' => $now->subDays(10),
            'document_uploaded_at' => $now->subDays(13),
            'last_milestone_updated_at' => $now->subDays(5),
            'created_by_user_id' => $this->users['admin.dpupr@pastara.id']->id,
        ]);
        BudgetMilestone::create([
            'budget_schedule_id' => $schedule->id,
            'updated_by_user_id' => $this->users['admin.dpupr@pastara.id']->id,
            'quarter_label' => '2026-Q4',
            'progress_note' => 'Survei teknis dan penyusunan DED rekonstruksi ruas Sadabumi–Ujungbarang telah selesai diverifikasi.',
            'created_at' => $now->subDays(5),
        ]);
        $this->appendAudit($sada, $wargaDemo, 'WARGA', null, AuditActionType::SUBMITTED, null, ReportStatus::SUBMITTED, 'Laporan kerusakan jalan dikirim oleh warga.', $now->subDays(18));
        $this->appendAudit($sada, null, 'SYSTEM', null, AuditActionType::ROUTED, ReportStatus::SUBMITTED, ReportStatus::ROUTED_TO_UNIT, 'Dirutekan otomatis ke DPUPR.', $now->subDays(18));
        $this->appendAudit($sada, $this->users['admin.dpupr@pastara.id'], 'ADMIN_UNIT', $this->units['DPUPR']->name, AuditActionType::BUDGET_SCHEDULED, ReportStatus::ROUTED_TO_UNIT, ReportStatus::BUDGET_SCHEDULED, 'Dijadwalkan pada APBD Tahun Anggaran 2027 Q2 (RKA-DPUPR-2027-014).', $now->subDays(16));

        // 2. PST-PURW2027 — Mode Rahasia Ber-Token (Purwodadi)
        $cat06 = $this->categories['CAT-06'];
        $purw = Report::create([
            'ticket_code' => 'PST-PURW2027',
            'reporter_id' => $wargaDemo->id,
            'category_id' => $cat06->id,
            'opd_unit_id' => $this->units['DISDIKBUD']->id,
            'district_id' => $this->districts['Patimuan']->id,
            'village_id' => $this->villages['Patimuan:Purwodadi']->id,
            'title' => 'Aduan Keamanan Siswa Lingkungan Sekolah Dasar di Kec. Patimuan',
            'description_sanitized' => 'Terdapat gangguan keamanan siswa dan pagar sekolah yang rusak di area SD wilayah Patimuan sehingga memerlukan penanganan pengawasan segera. Kontak wali murid [NO. HP DISENSOR].',
            'latitude' => -7.5890000,
            'longitude' => 108.7710000,
            'address_detail' => 'Lingkungan Sekolah Dasar Desa Purwodadi, Kec. Patimuan',
            'is_secret' => true,
            'secret_token_hash' => Report::hashSecretToken('TKN-DEMO-2027'),
            'resolution_track' => ResolutionTrack::OPERATIONAL,
            'status' => ReportStatus::IN_PROGRESS,
            'current_cycle' => 1,
            'issue_type_snapshot' => $cat06->issue_type,
            'duplicate_radius_snapshot_m' => $cat06->duplicate_radius_m,
            'geotag_tolerance_snapshot_m' => $cat06->geotag_tolerance_m,
            'sla_hours_snapshot' => $cat06->default_sla_hours,
            'sla_started_at' => $now->subHours(18),
            'sla_cycle_started_at' => $now->subHours(18),
            'sla_deadline_at' => $now->addHours(54),
            'created_at' => $now->subHours(20),
            'updated_at' => $now->subHours(18),
        ]);
        $this->attachPhoto($purw, $wargaDemo, PhotoType::BEFORE, 1, 1);
        $this->appendAudit($purw, $wargaDemo, 'WARGA', null, AuditActionType::SUBMITTED, null, ReportStatus::SUBMITTED, 'Laporan sensitif mode rahasia dikirim.', $now->subHours(20));
        $this->appendAudit($purw, null, 'SYSTEM', null, AuditActionType::ROUTED, ReportStatus::SUBMITTED, ReportStatus::ROUTED_TO_UNIT, 'Dirutekan otomatis ke Disdikbud.', $now->subHours(20));
        $this->appendAudit($purw, $this->users['admin.disdikbud@pastara.id'], 'ADMIN_UNIT', $this->units['DISDIKBUD']->name, AuditActionType::ASSIGNED, ReportStatus::ROUTED_TO_UNIT, ReportStatus::IN_PROGRESS, 'Ditugaskan untuk peninjauan lapangan tanpa membuka identitas pelapor.', $now->subHours(18));

        // 3. PST-ESCROW01 — Pending Citizen Sign-Off (Before-After Slider)
        $cat02 = $this->categories['CAT-02'];
        $officerDishub = $this->users['petugas.dishub@pastara.id'];
        $escrow = Report::create([
            'ticket_code' => 'PST-ESCROW01',
            'reporter_id' => $wargaDemo->id,
            'category_id' => $cat02->id,
            'opd_unit_id' => $this->units['DISHUB']->id,
            'assigned_officer_id' => $officerDishub->id,
            'district_id' => $this->districts['Cilacap Tengah']->id,
            'village_id' => $this->villages['Cilacap Tengah:Sidanegara']->id,
            'title' => 'Lampu PJU Padam di Jl. Gatot Subroto, Cilacap Tengah',
            'description_sanitized' => 'Lampu PJU di depan pertigaan padam sejak 2 malam lalu. Hubungi saya di [NO. HP DISENSOR].',
            'latitude' => -7.7188000,
            'longitude' => 109.0154000,
            'address_detail' => 'Depan pertigaan utama Jl. Gatot Subroto',
            'is_secret' => false,
            'resolution_track' => ResolutionTrack::OPERATIONAL,
            'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
            'current_cycle' => 1,
            'issue_type_snapshot' => $cat02->issue_type,
            'duplicate_radius_snapshot_m' => $cat02->duplicate_radius_m,
            'geotag_tolerance_snapshot_m' => $cat02->geotag_tolerance_m,
            'sla_hours_snapshot' => $cat02->default_sla_hours,
            'sla_started_at' => $now->subHours(12),
            'sla_cycle_started_at' => $now->subHours(12),
            'sla_paused_at' => $now->subHours(2),
            'sla_deadline_at' => $now->addHours(12),
            'signoff_started_at' => $now->subHours(2),
            'signoff_deadline_at' => $now->addHours(70),
            'geotag_warning' => false,
            'geotag_distance_m' => 28,
            'officer_action_note' => 'Bohlam LED 90W dan ballast yang korslet telah diganti baru.',
            'upvote_count' => 8,
            'created_at' => $now->subHours(14),
            'updated_at' => $now->subHours(2),
        ]);
        $this->attachPhoto($escrow, $wargaDemo, PhotoType::BEFORE, 1, 1);
        $this->attachPhoto($escrow, $officerDishub, PhotoType::AFTER, 1, 1, -7.7186000, 109.0155000, $now->subHours(3), 28, false);
        $this->seedUpvotes($escrow, 8);
        $this->appendAudit($escrow, $wargaDemo, 'WARGA', null, AuditActionType::SUBMITTED, null, ReportStatus::SUBMITTED, 'Laporan lampu PJU padam dikirim.', $now->subHours(14));
        $this->appendAudit($escrow, $this->users['admin.dishub@pastara.id'], 'ADMIN_UNIT', $this->units['DISHUB']->name, AuditActionType::ASSIGNED, ReportStatus::ROUTED_TO_UNIT, ReportStatus::IN_PROGRESS, 'Petugas PJU Dishub ditugaskan ke lokasi.', $now->subHours(12));
        $this->appendAudit($escrow, $officerDishub, 'PETUGAS', $this->units['DISHUB']->name, AuditActionType::EVIDENCE_SUBMITTED, ReportStatus::IN_PROGRESS, ReportStatus::PENDING_CITIZEN_SIGN_OFF, 'Perbaikan selesai, menunggu konfirmasi warga (72 jam).', $now->subHours(2));

        // 4. PST-VETO0001 — Disputed (Geotag Warning 620m, 3 foto tersedia)
        $officerDpupr = $this->users['petugas.dpupr@pastara.id'];
        $veto = Report::create([
            'ticket_code' => 'PST-VETO0001',
            'reporter_id' => $wargaDemo->id,
            'category_id' => $cat01->id,
            'opd_unit_id' => $this->units['DPUPR']->id,
            'assigned_officer_id' => $officerDpupr->id,
            'district_id' => $this->districts['Kroya']->id,
            'village_id' => $this->villages['Kroya:Kroya']->id,
            'title' => 'Tambal Sulam Jalan Berlubang di Kec. Kroya',
            'description_sanitized' => 'Lubang jalan cukup dalam di dekat pasar Kroya sering menyebabkan pengendara motor terjatuh.',
            'latitude' => -7.6305000,
            'longitude' => 109.2470000,
            'address_detail' => 'Jl. Ahmad Yani, sebelah barat Pasar Kroya',
            'is_secret' => false,
            'resolution_track' => ResolutionTrack::OPERATIONAL,
            'status' => ReportStatus::DISPUTED,
            'current_cycle' => 1,
            'issue_type_snapshot' => $cat01->issue_type,
            'duplicate_radius_snapshot_m' => $cat01->duplicate_radius_m,
            'geotag_tolerance_snapshot_m' => $cat01->geotag_tolerance_m,
            'sla_hours_snapshot' => $cat01->default_sla_hours,
            'sla_started_at' => $now->subDays(4),
            'sla_cycle_started_at' => $now->subDays(4),
            'sla_paused_at' => $now->subDays(2),
            'sla_deadline_at' => $now->subDay(),
            'signoff_started_at' => $now->subDays(2),
            'signoff_deadline_at' => $now->addDay(),
            'geotag_warning' => true,
            'geotag_distance_m' => 620,
            'officer_action_note' => 'Penambalan lubang dengan coldmix telah dilakukan.',
            'upvote_count' => 6,
            'created_at' => $now->subDays(5),
            'updated_at' => $now->subDay(),
        ]);
        $this->attachPhoto($veto, $wargaDemo, PhotoType::BEFORE, 1, 1);
        $this->attachPhoto($veto, $officerDpupr, PhotoType::AFTER, 1, 1, -7.6250000, 109.2480000, $now->subDays(2), 620, true);
        $this->attachPhoto($veto, $wargaDemo, PhotoType::DISPUTE, 1, 1);
        $this->seedUpvotes($veto, 6);
        Dispute::create([
            'report_id' => $veto->id,
            'cycle' => 1,
            'raised_by_user_id' => $wargaDemo->id,
            'citizen_reason' => 'Foto perbaikan yang diunggah petugas bukan di titik lubang depan Pasar Kroya, lokasi masih berlubang dan tergenang air.',
            'status' => DisputeStatus::PENDING,
        ]);
        $this->appendAudit($veto, $officerDpupr, 'PETUGAS', $this->units['DPUPR']->name, AuditActionType::EVIDENCE_SUBMITTED, ReportStatus::IN_PROGRESS, ReportStatus::PENDING_CITIZEN_SIGN_OFF, 'Klaim perbaikan diunggah (terdeteksi peringatan geotag 620 m).', $now->subDays(2));
        $this->appendAudit($veto, $wargaDemo, 'WARGA', null, AuditActionType::CITIZEN_REJECTED, ReportStatus::PENDING_CITIZEN_SIGN_OFF, ReportStatus::DISPUTED, 'Pelapor mengajukan veto terhadap bukti perbaikan.', $now->subDay());

        // 5. PST-DUPL0001 — In Progress Zona Kuning (-7.6350, 109.0180, 14 Upvote)
        $cat03 = $this->categories['CAT-03'];
        $officerDlh = $this->users['petugas.dlh@pastara.id'];
        $dupl = Report::create([
            'ticket_code' => 'PST-DUPL0001',
            'reporter_id' => $this->simCitizens[0]->id,
            'category_id' => $cat03->id,
            'opd_unit_id' => $this->units['DLH']->id,
            'assigned_officer_id' => $officerDlh->id,
            'district_id' => $this->districts['Jeruklegi']->id,
            'village_id' => $this->villages['Jeruklegi:Jeruklegi Kulon']->id,
            'title' => 'Tumpukan Sampah Liar Tepi Jalan Raya Jeruklegi',
            'description_sanitized' => 'Tumpukan sampah rumah tangga menumpuk di bahu Jalan Raya Jeruklegi dan menimbulkan bau menyengat bagi pengguna jalan.',
            'latitude' => -7.6350000,
            'longitude' => 109.0180000,
            'address_detail' => 'Bahu Jalan Raya Jeruklegi KM 11',
            'is_secret' => false,
            'resolution_track' => ResolutionTrack::OPERATIONAL,
            'status' => ReportStatus::IN_PROGRESS,
            'current_cycle' => 1,
            'issue_type_snapshot' => $cat03->issue_type,
            'duplicate_radius_snapshot_m' => $cat03->duplicate_radius_m,
            'geotag_tolerance_snapshot_m' => $cat03->geotag_tolerance_m,
            'sla_hours_snapshot' => $cat03->default_sla_hours,
            'sla_started_at' => $now->subHours(38),
            'sla_cycle_started_at' => $now->subHours(38),
            'sla_deadline_at' => $now->addHours(10),
            'sla_warning_notified_at' => $now->subHours(4),
            'upvote_count' => 14,
            'created_at' => $now->subHours(40),
            'updated_at' => $now->subHours(4),
        ]);
        $this->attachPhoto($dupl, $this->simCitizens[0], PhotoType::BEFORE, 1, 1);
        $this->seedUpvotes($dupl, 14, true);
        $this->appendAudit($dupl, $this->simCitizens[0], 'WARGA', null, AuditActionType::SUBMITTED, null, ReportStatus::SUBMITTED, 'Laporan tumpukan sampah liar dikirim.', $now->subHours(40));
        $this->appendAudit($dupl, $this->users['admin.dlh@pastara.id'], 'ADMIN_UNIT', $this->units['DLH']->name, AuditActionType::ASSIGNED, ReportStatus::ROUTED_TO_UNIT, ReportStatus::IN_PROGRESS, 'Petugas DLH ditugaskan mengangkut tumpukan sampah.', $now->subHours(38));
        $this->appendAudit($dupl, null, 'SYSTEM', null, AuditActionType::SLA_WARNING, ReportStatus::IN_PROGRESS, ReportStatus::IN_PROGRESS, 'SLA memasuki Zona Kuning (>= 70%).', $now->subHours(4));

        Notification::create([
            'user_id' => $wargaDemo->id,
            'report_id' => $escrow->id,
            'type' => NotificationType::SIGN_OFF_REQUESTED,
            'title' => NotificationType::SIGN_OFF_REQUESTED->defaultTitle(),
            'message' => 'Petugas Dishub telah mengunggah bukti perbaikan untuk tiket PST-ESCROW01. Mohon konfirmasi dalam 72 jam.',
            'created_at' => $now->subHours(2),
        ]);
    }

    private function seedBackgroundTickets(): void
    {
        $now = now('UTC');
        $statusPool = [
            ReportStatus::ROUTED_TO_UNIT,
            ReportStatus::ROUTED_TO_UNIT,
            ReportStatus::IN_PROGRESS,
            ReportStatus::IN_PROGRESS,
            ReportStatus::IN_PROGRESS,
            ReportStatus::BUDGET_SCHEDULED,
            ReportStatus::PENDING_CITIZEN_SIGN_OFF,
            ReportStatus::DISPUTED,
            ReportStatus::REOPENED_BY_AUDIT,
            ReportStatus::VERIFIED_CLOSED,
            ReportStatus::VERIFIED_CLOSED,
            ReportStatus::AUTO_VERIFIED_CLOSED,
            ReportStatus::CLOSED_BY_AUDIT,
            ReportStatus::REJECTED,
        ];

        $categoryCodes = ['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04', 'CAT-05', 'CAT-06', 'CAT-07'];
        $locationPairs = [
            ['Cilacap Tengah', 'Sidanegara', -7.7160, 109.0120],
            ['Cilacap Tengah', 'Donan', -7.7240, 109.0050],
            ['Cilacap Selatan', 'Tambakreja', -7.7340, 109.0180],
            ['Cilacap Utara', 'Gumilir', -7.6850, 109.0350],
            ['Kesugihan', 'Kesugihan', -7.6280, 109.1050],
            ['Maos', 'Maos Kidul', -7.6180, 109.1460],
            ['Kroya', 'Bajing', -7.6380, 109.2420],
            ['Adipala', 'Adipala', -7.6580, 109.1680],
            ['Sidareja', 'Sidareja', -7.4850, 108.7920],
            ['Majenang', 'Jenang', -7.2980, 108.7640],
            ['Wanareja', 'Wanareja', -7.3340, 108.6980],
            ['Jeruklegi', 'Jeruklegi Wetan', -7.6410, 109.0290],
        ];

        $officerByUnit = [
            'DPUPR' => $this->users['petugas.dpupr@pastara.id'],
            'DISHUB' => $this->users['petugas.dishub@pastara.id'],
            'DLH' => $this->users['petugas.dlh@pastara.id'],
        ];

        for ($i = 1; $i <= 47; $i++) {
            $status = $statusPool[($i - 1) % count($statusPool)];
            $cat = $this->categories[$categoryCodes[($i - 1) % count($categoryCodes)]];
            [$districtName, $villageName, $baseLat, $baseLng] = $locationPairs[($i - 1) % count($locationPairs)];
            $district = $this->districts[$districtName];
            $village = $this->villages["{$districtName}:{$villageName}"];

            $unit = $cat->route_by_village
                ? ($village->opdUnit ?? $this->units['DESA_UMUM'])
                : $cat->defaultOpdUnit;

            $reporter = $this->simCitizens[$i % count($this->simCitizens)];
            $officer = $officerByUnit[$unit->code] ?? null;
            $isSecret = $cat->recommend_secret && ($i % 2 === 0);
            $createdAt = $now->subDays(($i % 25) + 1);
            $ticketCode = sprintf('PST-SIM%05d', $i);

            $track = match ($status) {
                ReportStatus::BUDGET_SCHEDULED => ResolutionTrack::APBD,
                ReportStatus::ROUTED_TO_UNIT, ReportStatus::REJECTED => null,
                default => ResolutionTrack::OPERATIONAL,
            };

            $slaHours = $cat->default_sla_hours;
            $isOverdue = ($status === ReportStatus::IN_PROGRESS && $i % 5 === 0)
                || $status === ReportStatus::REOPENED_BY_AUDIT;
            $isLate = $isOverdue || ($status === ReportStatus::CLOSED_BY_AUDIT && $i % 2 === 0);

            $slaStarted = $track === ResolutionTrack::OPERATIONAL ? $createdAt->addHours(2) : null;
            $slaDeadline = $slaStarted?->addHours($slaHours);
            if ($isOverdue && $slaStarted !== null) {
                $slaStarted = $now->subHours($slaHours + 12);
                $slaDeadline = $now->subHours(6);
            }

            $report = Report::create([
                'ticket_code' => $ticketCode,
                'reporter_id' => $reporter->id,
                'category_id' => $cat->id,
                'opd_unit_id' => $unit->id,
                'assigned_officer_id' => $track === ResolutionTrack::OPERATIONAL ? $officer?->id : null,
                'district_id' => $district->id,
                'village_id' => $village->id,
                'title' => sprintf('Laporan %s #%02d Kec. %s', $cat->name, $i, $districtName),
                'description_sanitized' => "Permasalahan {$cat->name} terpantau di wilayah {$villageName}, Kecamatan {$districtName} dan membutuhkan tindak lanjut sesuai standar layanan.",
                'latitude' => round($baseLat + (($i % 5) * 0.0012), 7),
                'longitude' => round($baseLng + (($i % 4) * 0.0015), 7),
                'address_detail' => "Sekitar {$villageName}, Kec. {$districtName}",
                'is_secret' => $isSecret,
                'secret_token_hash' => $isSecret ? Report::hashSecretToken(sprintf('TKN-SIM%02d-2027', $i)) : null,
                'resolution_track' => $track,
                'status' => $status,
                'current_cycle' => $status === ReportStatus::REOPENED_BY_AUDIT ? 2 : 1,
                'issue_type_snapshot' => $cat->issue_type,
                'duplicate_radius_snapshot_m' => $cat->duplicate_radius_m,
                'geotag_tolerance_snapshot_m' => $cat->geotag_tolerance_m,
                'sla_hours_snapshot' => $slaHours,
                'sla_started_at' => $slaStarted,
                'sla_cycle_started_at' => $slaStarted,
                'sla_paused_at' => in_array($status, [ReportStatus::PENDING_CITIZEN_SIGN_OFF, ReportStatus::DISPUTED], true) ? $now->subHours(6) : null,
                'sla_deadline_at' => $slaDeadline,
                'sla_stopped_at' => $status->isFinal() && $status !== ReportStatus::REJECTED ? $now->subDays(1) : null,
                'signoff_started_at' => $status === ReportStatus::PENDING_CITIZEN_SIGN_OFF ? $now->subHours(8) : null,
                'signoff_deadline_at' => $status === ReportStatus::PENDING_CITIZEN_SIGN_OFF ? $now->addHours(64) : null,
                'is_sla_overdue' => $isOverdue,
                'is_milestone_overdue' => $status === ReportStatus::BUDGET_SCHEDULED && $i % 2 === 0,
                'is_late' => $isLate,
                'officer_action_note' => in_array($status, [ReportStatus::PENDING_CITIZEN_SIGN_OFF, ReportStatus::VERIFIED_CLOSED, ReportStatus::AUTO_VERIFIED_CLOSED], true)
                    ? 'Penanganan teknis lapangan telah selesai dilaksanakan.'
                    : null,
                'rejection_reason_code' => $status === ReportStatus::REJECTED ? RejectionReasonCode::INCOMPLETE : null,
                'rejection_note' => $status === ReportStatus::REJECTED ? 'Titik lokasi dan uraian masalah belum memadai untuk diverifikasi di lapangan.' : null,
                'upvote_count' => $isSecret ? 0 : ($i % 5),
                'created_at' => $createdAt,
                'updated_at' => $createdAt->addHours(4),
            ]);

            $this->attachPhoto($report, $reporter, PhotoType::BEFORE, 1, 1);

            if (! $isSecret && ($i % 5) > 0) {
                $this->seedUpvotes($report, $i % 5);
            }

            if ($status === ReportStatus::BUDGET_SCHEDULED) {
                BudgetSchedule::create([
                    'report_id' => $report->id,
                    'fiscal_year' => 2027,
                    'proposal_ref_number' => sprintf('RKA-2027-%03d', $i),
                    'target_quarter' => 'Q3',
                    'estimated_budget' => 250000000.00,
                    'document_due_at' => $report->is_milestone_overdue ? $now->subDays(2) : $now->addDays(5),
                    'last_milestone_updated_at' => $now->subDays(10),
                    'created_by_user_id' => $this->users['admin.dpupr@pastara.id']->id,
                ]);
            }

            if (in_array($status, [ReportStatus::PENDING_CITIZEN_SIGN_OFF, ReportStatus::DISPUTED, ReportStatus::VERIFIED_CLOSED, ReportStatus::AUTO_VERIFIED_CLOSED, ReportStatus::CLOSED_BY_AUDIT], true)) {
                $this->attachPhoto($report, $officer ?? $reporter, PhotoType::AFTER, 1, 1, $report->latitude, $report->longitude, $now->subHours(10), 18, false);
            }

            if (in_array($status, [ReportStatus::DISPUTED, ReportStatus::REOPENED_BY_AUDIT, ReportStatus::CLOSED_BY_AUDIT], true)) {
                $this->attachPhoto($report, $reporter, PhotoType::DISPUTE, 1, 1);
                Dispute::create([
                    'report_id' => $report->id,
                    'cycle' => 1,
                    'raised_by_user_id' => $reporter->id,
                    'citizen_reason' => 'Hasil penanganan fisik di lokasi dinilai belum sesuai dengan kondisi yang dilaporkan.',
                    'status' => match ($status) {
                        ReportStatus::DISPUTED => DisputeStatus::PENDING,
                        ReportStatus::REOPENED_BY_AUDIT => DisputeStatus::REOPENED,
                        default => DisputeStatus::CLOSED,
                    },
                    'auditor_id' => $status === ReportStatus::DISPUTED ? null : $this->users['auditor.inspektorat@pastara.id']->id,
                    'audit_notes' => $status === ReportStatus::DISPUTED ? null : 'Hasil pemeriksaan lapangan Inspektorat telah dicatat.',
                    'reopen_sla_hours' => $status === ReportStatus::REOPENED_BY_AUDIT ? 48 : null,
                    'resolved_at' => $status === ReportStatus::DISPUTED ? null : $now->subHours(12),
                ]);
            }

            if (in_array($status, [ReportStatus::VERIFIED_CLOSED, ReportStatus::AUTO_VERIFIED_CLOSED], true)) {
                Rating::create([
                    'report_id' => $report->id,
                    'user_id' => $reporter->id,
                    'speed_score' => ($i % 2) + 4,
                    'physical_quality_score' => ($i % 2) + 4,
                    'comment' => 'Penanganan berjalan baik dan bukti perbaikan jelas.',
                    'created_at' => $now->subHours(6),
                ]);
            }

            $this->appendAudit($report, $reporter, 'WARGA', null, AuditActionType::SUBMITTED, null, ReportStatus::SUBMITTED, 'Tiket simulasi dikirim.', $createdAt);
            $this->appendAudit($report, null, 'SYSTEM', null, AuditActionType::ROUTED, ReportStatus::SUBMITTED, ReportStatus::ROUTED_TO_UNIT, "Dirutekan ke {$unit->code}.", $createdAt->addMinutes(1));
        }
    }

    private function attachPhoto(
        Report $report,
        User $uploader,
        PhotoType $type,
        int $cycle,
        int $sequence,
        ?float $exifLat = null,
        ?float $exifLng = null,
        ?CarbonInterface $exifTakenAt = null,
        ?int $distanceM = null,
        bool $geotagWarning = false
    ): void {
        $mediaKey = strtolower($type->value);

        ReportPhoto::create([
            'report_id' => $report->id,
            'uploaded_by_user_id' => $uploader->id,
            'photo_type' => $type,
            'cycle' => $cycle,
            'sequence' => $sequence,
            'file_path' => $this->dummyMedia[$mediaKey]['file'],
            'thumbnail_path' => $this->dummyMedia[$mediaKey]['thumb'],
            'exif_latitude' => $exifLat,
            'exif_longitude' => $exifLng,
            'exif_taken_at' => $exifTakenAt,
            'distance_from_report_m' => $distanceM,
            'has_geotag_warning' => $geotagWarning,
            'created_at' => now('UTC'),
        ]);
    }

    private function seedUpvotes(Report $report, int $count, bool $skipFirst = false): void
    {
        $offset = $skipFirst ? 1 : 0;
        for ($j = 0; $j < $count; $j++) {
            $citizen = $this->simCitizens[($j + $offset) % count($this->simCitizens)] ?? null;
            if ($citizen === null || $citizen->id === $report->reporter_id) {
                continue;
            }

            ReportUpvote::firstOrCreate(
                ['report_id' => $report->id, 'user_id' => $citizen->id],
                ['is_watching' => true]
            );
        }
    }

    private function appendAudit(
        Report $report,
        ?User $actor,
        string $actorRole,
        ?string $actorUnitName,
        AuditActionType $actionType,
        ?ReportStatus $fromStatus,
        ?ReportStatus $toStatus,
        string $note,
        CarbonInterface $createdAt
    ): void {
        AuditLog::create([
            'report_id' => $report->id,
            'actor_user_id' => $actor?->id,
            'actor_role' => $actorRole,
            'actor_unit_name' => $actorUnitName,
            'action_type' => $actionType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'is_public_visible' => $actionType->isPublicVisible(),
            'created_at' => $createdAt,
        ]);
    }
}
