# PASTARA API (`pastara-api`)

Backend REST API untuk **PASTARA** (*Platform Pengaduan & Resolusi Layanan Publik Dua Arah Berbasis Escrow Citizen Sign-Off dan Penegakan SLA*) — studi kasus peningkatan *Portal Lapor & Sambat Bupati Kabupaten Cilacap* untuk **Techcomfest 2027**.

---

## Fitur & Arsitektur Inti

- **Laravel 13 (`^13.0`) + PHP `^8.3` + PostgreSQL 15+** dengan autentikasi stateless **Laravel Sanctum** (`Bearer Token`, masa berlaku 24 jam).
- **Escrow Citizen Sign-Off (Hukum 1):** Admin Unit, Petugas, maupun Super Admin dilarang menutup tiket secara sepihak (`HTTP 403 ESCROW_LOCK_VIOLATION`). Penutupan hanya melalui persetujuan warga (`VERIFIED_CLOSED`), auto-verifikasi 72 jam (`AUTO_VERIFIED_CLOSED`), atau putusan Inspektorat (`CLOSED_BY_AUDIT`).
- **Transisi Atomik & Row-Level Locking (Hukum 2):** Seluruh transisi status (`TR-01` s.d. `TR-13`) terpusat pada `ReportStateMachineService` dengan `DB::transaction()` dan `lockForUpdate()`.
- **Immutable Audit Trail (Hukum 3):** Tabel `audit_logs` bersifat *insert-only* dengan proteksi model PHP dan trigger PostgreSQL `trg_audit_logs_immutable`.
- **Mode Rahasia Ber-Token (Hukum 4):** Tiket `is_secret = true` difilter keluar dari peta/jelajah publik & deteksi duplikat, disensor pada tampilan Unit/Petugas, dan dilacak dengan token rahasia `HMAC-SHA256`.
- **Integritas Waktu Server & Snapshot (Hukum 5):** Seluruh timestamp disimpan dalam UTC (`TIMESTAMPTZ`), setiap respons JSON menyertakan `server_time`, dan parameter SLA/radius/geotag di-*snapshot* saat tiket dibuat.
- **Pemrosesan Foto & Geospasial:** Validasi EXIF GPS + usia foto (`≤ 24 jam`), sanitasi strip EXIF ke WebP + thumbnail via `intervention/image`, serta deteksi duplikat Haversine radius dinamis (`200 m` / `750 m`).
- **43 Endpoint REST API (51 Operasi HTTP)** lintas modul `M01`–`M11` dan **4 Artisan Scheduler Commands** (`M07`).

---

## Prasyarat

- **PHP** `>= 8.3` dengan ekstensi: `pdo_pgsql`, `pgsql`, `gd` (dengan dukungan WebP), `exif`, `intl`, `mbstring`, `bcmath`, `zip`
- **Composer** `>= 2.x`
- **PostgreSQL** `>= 15`
- *(Opsional)* **Docker** untuk menjalankan build container produksi (`Dockerfile`)

---

## Instalasi Lokal (≤ 5 Menit)

### 1. Clone Repositori & Masuk ke Direktori Backend

```bash
git clone https://github.com/afifnoer/pastara.git
cd pastara/pastara-api
```

### 2. Instal Dependensi & Siapkan `.env`

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 3. Konfigurasi Database PostgreSQL

Buat database PostgreSQL lokal (misalnya `pastara_db`), lalu sesuaikan konfigurasi di `.env`:

```dotenv
APP_TIMEZONE=UTC
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
API_DOCS_ENABLED=true

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pastara_db
DB_USERNAME=postgres
DB_PASSWORD=your_postgres_password
```

Parameter domain Cilacap & Escrow di `.env` (nilai default sudah tersedia di `.env.example`):

```dotenv
SANCTUM_TOKEN_EXPIRATION_MINUTES=1440
CILACAP_MIN_LAT=-7.8500
CILACAP_MAX_LAT=-7.1500
CILACAP_MIN_LNG=108.5000
CILACAP_MAX_LNG=109.4500
SIGNOFF_WINDOW_HOURS=72
SIGNOFF_REMINDER_REMAINING_HOURS=24
APBD_DOC_DUE_WEEKDAYS=7
EXIF_MAX_AGE_HOURS=24
```

### 4. Migrasi, Seeder Simulasi Cilacap, & Storage Link

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

Perintah `migrate:fresh --seed` menjalankan `CilacapSimulationSeeder` yang mengisi:
- 8 Unit Kerja (`DPUPR`, `DISHUB`, `DLH`, `DISDIKBUD`, `INSPEKTORAT`, `DESA_SADABUMI`, `DESA_PURWODADI`, `DESA_UMUM`)
- 7 Kategori Masalah (`CAT-01` s.d. `CAT-07`)
- 24 Kecamatan & desa/kelurahan Kabupaten Cilacap
- 11 Akun Uji Default + 15 akun warga simulasi
- 5 *Hero Tickets* + ≥ 45 tiket simulasi lintas status beserta foto WebP dummy, jadwal APBD, sengketa, rating, dan audit log

### 5. Jalankan Server Lokal

```bash
php artisan serve
```

Verifikasi cepat:
- Health Check: `GET http://localhost:8000/api/v1/health`
- Rapor Kepatuhan OPD: `GET http://localhost:8000/api/v1/public/scorecard`
- Dokumentasi API Interaktif (Scalar): `http://localhost:8000/docs`
- Spesifikasi OpenAPI 3.0.3 YAML: `http://localhost:8000/docs/openapi.yaml`

---

## Menjalankan Scheduler & Pengujian

### Artisan Scheduler Commands (`M07`)

Jalankan scheduler secara kontinu saat pengembangan lokal:

```bash
php artisan schedule:work
```

Atau eksekusi manual tiap command idempoten:

| Perintah | Jadwal Produksi | Fungsi |
|---|---|---|
| `php artisan pastara:evaluate-sla` | Setiap menit | Evaluasi Zona Kuning (`R ≥ 70%`) & Overdue (`R ≥ 100%`) |
| `php artisan pastara:auto-verify-signoff` | Setiap menit | Pengingat sisa 24 jam & auto-verifikasi Escrow 72 jam (`TR-10`) |
| `php artisan pastara:evaluate-apbd-milestones` | Harian `00:05 WIB` | Evaluasi tenggat 7 hari kerja dokumen RKA & milestone triwulanan APBD |
| `php artisan pastara:recalculate-scorecard` | Setiap 5 menit | Memperbarui cache metrik rapor kepatuhan pada `opd_units` |

### Automated Test Suite & Code Style (Pint)

```bash
# Jalankan seluruh 35 file test (Unit, Feature, E2E, Security, Concurrency Row-Locking)
php artisan test

# Periksa standar gaya kode PSR-12 dengan Laravel Pint
./vendor/bin/pint --test
```

---

## Daftar 11 Akun Uji Default

Seluruh akun uji menggunakan kata sandi default: **`Pastara2027!`**

| # | Nama | Email | Role | Unit Kerja |
|---|---|---|---|---|
| 1 | Warga Pelapor Uji | `warga.demo@pastara.id` | `WARGA` | — |
| 2 | Admin DPUPR Cilacap | `admin.dpupr@pastara.id` | `ADMIN_UNIT` | `DPUPR` |
| 3 | Admin Dishub Cilacap | `admin.dishub@pastara.id` | `ADMIN_UNIT` | `DISHUB` |
| 4 | Admin DLH Cilacap | `admin.dlh@pastara.id` | `ADMIN_UNIT` | `DLH` |
| 5 | Admin Disdikbud Cilacap | `admin.disdikbud@pastara.id` | `ADMIN_UNIT` | `DISDIKBUD` |
| 6 | Admin Desa Sadabumi | `admin.sadabumi@pastara.id` | `ADMIN_UNIT` | `DESA_SADABUMI` |
| 7 | Petugas Teknis DPUPR | `petugas.dpupr@pastara.id` | `PETUGAS` | `DPUPR` |
| 8 | Petugas PJU Dishub | `petugas.dishub@pastara.id` | `PETUGAS` | `DISHUB` |
| 9 | Petugas Kebersihan DLH | `petugas.dlh@pastara.id` | `PETUGAS` | `DLH` |
| 10 | Auditor Inspektorat Daerah | `auditor.inspektorat@pastara.id` | `INSPEKTORAT` | `INSPEKTORAT` |
| 11 | Super Admin Pastara | `superadmin@pastara.id` | `SUPER_ADMIN` | — |

---

## Daftar 5 *Hero Tickets* Simulasi Cilacap

| Kode Tiket | Kategori & Unit | Status Awal | Skenario Uji Utama |
|---|---|---|---|
| `PST-SADA2027` | `CAT-01` · `DPUPR` (Majenang / Sadabumi) | `BUDGET_SCHEDULED` | Jalur 2 APBD 2027 (`RKA-DPUPR-2027-014`), milestone triwulanan, tidak dihitung sebagai tuntas fisik |
| `PST-PURW2027` | `CAT-06` · `DISDIKBUD` (Patimuan / Purwodadi) | `IN_PROGRESS` | Mode Rahasia (`is_secret = true`), token pelacakan **`TKN-DEMO-2027`**, tersembunyi dari peta/jelajah publik & disensor di Unit |
| `PST-ESCROW01` | `CAT-02` · `DISHUB` (Cilacap Tengah / Sidanegara) | `PENDING_CITIZEN_SIGN_OFF` | Escrow Citizen Sign-Off (Before-After Slider, geotag valid 28 m, jendela 72 jam aktif) |
| `PST-VETO0001` | `CAT-01` · `DPUPR` (Kroya) | `DISPUTED` | Sengketa warga (Veto) dengan Geotag Warning (`620 m`), siap disidang Inspektorat |
| `PST-DUPL0001` | `CAT-03` · `DLH` (Jeruklegi) | `IN_PROGRESS` | Zona Kuning SLA (`-7.6350, 109.0180`, 14 Upvote), target uji deteksi duplikat Haversine `/reports/nearby` |

---

## Dokumentasi API & Koleksi Pengujian

- **Scalar API Reference UI:** `GET /docs` (dikendalikan via `API_DOCS_ENABLED=true|false`)
- **Spesifikasi OpenAPI 3.0.3:** `GET /docs/openapi.yaml` (sinkron dengan [../docs/openapi.yaml](../docs/openapi.yaml))
- **Koleksi API Bruno:** [../bruno/pastara-api](../bruno/pastara-api) (mencakup seluruh modul `M01`–`M11` & environment `Local` / `Production`)
- **Server Publik:**
  - **Staging (`develop`):** `https://staging.afiefnoer.my.id/api/v1` (Docs: `https://staging.afiefnoer.my.id/docs`)
  - **Production (`main`):** `https://afiefnoer.my.id/api/v1`

---

## Deployment Produksi (Docker / Dokploy)

Backend dilengkapi [Dockerfile](./Dockerfile) berbasis `php:8.3-fpm-alpine` + Nginx + Supervisor (`docker/*`):

1. Set environment produksi pada server/Dokploy:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=UTC`
   - `APP_URL=https://afiefnoer.my.id`, `FRONTEND_URL=<domain-pastara-web>`
   - Kredensial PostgreSQL produksi (`DB_CONNECTION=pgsql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)
   - `RUN_MIGRATIONS=true` (untuk menjalankan `php artisan migrate --force` saat startup container)
   - `API_DOCS_ENABLED=false` (atau `true` jika dokumentasi publik dibutuhkan)
2. Mount persistent volume pada `/var/www/html/storage/app/public` agar file WebP & PDF tidak hilang saat redeploy (`docker/entrypoint.sh` otomatis membuat symlink `public/storage`).
3. Jalankan seeder awal sekali di produksi:
   ```bash
   php artisan db:seed --class=CilacapSimulationSeeder --force
   ```
4. Pastikan cron scheduler berjalan setiap menit di server/Dokploy:
   ```bash
   * * * * * php /var/www/html/artisan schedule:run >> /dev/null 2>&1
   ```
5. Jalankan smoke test produksi:
   ```bash
   curl -fsS https://afiefnoer.my.id/api/v1/health
   curl -fsS https://afiefnoer.my.id/api/v1/public/scorecard
   curl -fsS https://afiefnoer.my.id/api/v1/public/reports/PST-ESCROW01
   ```

---

## Tautan Silang Proyek & Dokumen Teknis

- **Frontend SPA (`pastara-web`):** React (Vite) + Tailwind CSS + Leaflet.js *(repositori pendamping polyrepo/client `pastara-web` yang mengonsumsi `/api/v1/*` sesuai kontrak OpenAPI)*
- **Dokumen Teknis Proyek (`../docs/`):**
  1. [PRD_Pastara.md](../docs/PRD_Pastara.md) — Product Requirements Document
  2. [SRS_Pastara_BE.md](../docs/SRS_Pastara_BE.md) — Software Requirements Specification Backend
  3. [Database_Design_Pastara.md](../docs/Database_Design_Pastara.md) — Skema 14 Tabel, Indeks, & Trigger PostgreSQL
  4. [System_Architecture_Pastara.md](../docs/System_Architecture_Pastara.md) — Arsitektur Sistem & Pipeline Media
  5. [Project_Constitution_Pastara.md](../docs/Project_Constitution_Pastara.md) — 5 Hukum Mutlak & Standar Engineering
  6. [Implementation_Plan_Pastara.md](../docs/Implementation_Plan_Pastara.md) — Rencana Implementasi 7 Fase
  7. [Tasks_Pastara.md](../docs/Tasks_Pastara.md) — Daftar 60 Task Granular & Progres Pengerjaan
  8. [openapi.yaml](../docs/openapi.yaml) — Spesifikasi OpenAPI 3.0.3
