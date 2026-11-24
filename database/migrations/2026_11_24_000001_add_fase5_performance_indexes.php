<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reports_reporter_created ON reports (reporter_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_report_upvotes_user_watching ON report_upvotes (user_id, is_watching, report_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_budget_milestones_schedule_created ON budget_milestones (budget_schedule_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_unit_role_active ON users (opd_unit_id, role, is_active)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_users_unit_role_active');
        DB::statement('DROP INDEX IF EXISTS idx_budget_milestones_schedule_created');
        DB::statement('DROP INDEX IF EXISTS idx_report_upvotes_user_watching');
        DB::statement('DROP INDEX IF EXISTS idx_reports_reporter_created');
    }
};
