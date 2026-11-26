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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained('reports')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 25);
            $table->string('actor_unit_name', 150)->nullable();
            $table->string('action_type', 40);
            $table->string('from_status', 35)->nullable();
            $table->string('to_status', 35)->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_public_visible')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_audit_logs_report_time ON audit_logs (report_id, created_at ASC)');

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_logs_modification()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Table audit_logs is append-only. UPDATE and DELETE operations are strictly prohibited.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_audit_logs_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_logs_modification();
            SQL);
        } else {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->index(['report_id', 'created_at'], 'idx_audit_logs_report_time');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_audit_logs_immutable ON audit_logs;
                DROP FUNCTION IF EXISTS prevent_audit_logs_modification();
            SQL);
        }

        Schema::dropIfExists('audit_logs');
    }
};
