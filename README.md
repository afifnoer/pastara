# PASTARA

**Platform Pengaduan & Resolusi Layanan Publik Dua Arah Berbasis *Escrow Citizen Sign-Off* dan Penegakan SLA** — studi kasus peningkatan *Portal Lapor & Sambat Bupati Kabupaten Cilacap* (**Techcomfest 2027**).

---

## Struktur Repositori

| Direktori / Berkas | Deskripsi |
|---|---|
| [`pastara-api/`](./pastara-api/README.md) | Backend REST API (**Laravel 13** + **PostgreSQL 15+** + **Sanctum** + **Docker**) — lihat panduan instalasi lengkap di [pastara-api/README.md](./pastara-api/README.md) |
| [`docs/`](./docs/) | Dokumentasi teknis lengkap: PRD, SRS Backend, Database Design, System Architecture, Project Constitution, Implementation Plan, Tasks Checklist, & OpenAPI 3.0.3 |
| [`bruno/pastara-api/`](./bruno/pastara-api/) | Koleksi pengujian API Bruno untuk seluruh modul (`M01`–`M11`) |
| [`AGENTS.md`](./AGENTS.md) | Aturan Git workflow, konvensi branch, dan standar engineering |

---

## Quick Start Backend (`pastara-api`)

```bash
cd pastara-api
composer install
cp .env.example .env
php artisan key:generate
# Sesuaikan konfigurasi PostgreSQL di .env, lalu:
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

- **API Base URL (Lokal):** `http://localhost:8000/api/v1`
- **Dokumentasi API Interaktif (Scalar):** `http://localhost:8000/docs`
- **Server Staging (`develop`):** `https://staging.afiefnoer.my.id/api/v1` ([Docs](https://staging.afiefnoer.my.id/docs))
- **Server Production (`main`):** `https://afiefnoer.my.id/api/v1`

---

## Dokumen Proyek

- [PRD_Pastara.md](./docs/PRD_Pastara.md)
- [SRS_Pastara_BE.md](./docs/SRS_Pastara_BE.md)
- [Database_Design_Pastara.md](./docs/Database_Design_Pastara.md)
- [System_Architecture_Pastara.md](./docs/System_Architecture_Pastara.md)
- [Project_Constitution_Pastara.md](./docs/Project_Constitution_Pastara.md)
- [Implementation_Plan_Pastara.md](./docs/Implementation_Plan_Pastara.md)
- [Tasks_Pastara.md](./docs/Tasks_Pastara.md)
- [openapi.yaml](./docs/openapi.yaml)
