<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_code', 20)->unique();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('opd_unit_id')->constrained('opd_units')->restrictOnDelete();
            $table->foreignId('assigned_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->foreignId('village_id')->constrained('villages')->restrictOnDelete();
            $table->string('title', 120);
            $table->text('description_sanitized');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address_detail', 255)->nullable();
            $table->boolean('is_secret')->default(false);
            $table->string('secret_token_hash', 255)->nullable();
            $table->string('resolution_track', 20)->nullable();
            $table->string('status', 35);
            $table->smallInteger('current_cycle')->default(1);
            $table->string('issue_type_snapshot', 15);
            $table->integer('duplicate_radius_snapshot_m');
            $table->integer('geotag_tolerance_snapshot_m');
            $table->integer('sla_hours_snapshot');
            $table->timestampTz('sla_started_at')->nullable();
            $table->timestampTz('sla_cycle_started_at')->nullable();
            $table->timestampTz('sla_paused_at')->nullable();
            $table->timestampTz('sla_deadline_at')->nullable();
            $table->timestampTz('sla_stopped_at')->nullable();
            $table->timestampTz('sla_warning_notified_at')->nullable();
            $table->timestampTz('signoff_started_at')->nullable();
            $table->timestampTz('signoff_deadline_at')->nullable();
            $table->timestampTz('signoff_reminder_sent_at')->nullable();
            $table->boolean('is_sla_overdue')->default(false);
            $table->boolean('is_milestone_overdue')->default(false);
            $table->boolean('is_late')->default(false);
            $table->boolean('geotag_warning')->default(false);
            $table->integer('geotag_distance_m')->nullable();
            $table->text('officer_action_note')->nullable();
            $table->string('rejection_reason_code', 40)->nullable();
            $table->text('rejection_note')->nullable();
            $table->integer('upvote_count')->default(0);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_reports_public_list ON reports (is_secret, status, created_at DESC)');
            DB::statement('CREATE INDEX idx_reports_haversine_bbox ON reports (category_id, is_secret, latitude, longitude)');
            DB::statement('CREATE INDEX idx_reports_unit_inbox ON reports (opd_unit_id, status, sla_deadline_at)');
            DB::statement('CREATE INDEX idx_reports_officer_tasks ON reports (assigned_officer_id, status, sla_deadline_at ASC)');
            DB::statement('CREATE INDEX idx_reports_sla_scheduler ON reports (status, sla_deadline_at)');
            DB::statement('CREATE INDEX idx_reports_signoff_scheduler ON reports (status, signoff_deadline_at)');
        } else {
            Schema::table('reports', function (Blueprint $table): void {
                $table->index(['is_secret', 'status', 'created_at'], 'idx_reports_public_list');
                $table->index(['category_id', 'is_secret', 'latitude', 'longitude'], 'idx_reports_haversine_bbox');
                $table->index(['opd_unit_id', 'status', 'sla_deadline_at'], 'idx_reports_unit_inbox');
                $table->index(['assigned_officer_id', 'status', 'sla_deadline_at'], 'idx_reports_officer_tasks');
                $table->index(['status', 'sla_deadline_at'], 'idx_reports_sla_scheduler');
                $table->index(['status', 'signoff_deadline_at'], 'idx_reports_signoff_scheduler');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
