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
        Schema::create('budget_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained('reports')->cascadeOnDelete();
            $table->smallInteger('fiscal_year');
            $table->string('proposal_ref_number', 100);
            $table->string('target_quarter', 5);
            $table->decimal('estimated_budget', 15, 2)->nullable();
            $table->string('document_proof_path', 255)->nullable();
            $table->timestampTz('document_due_at');
            $table->timestampTz('document_uploaded_at')->nullable();
            $table->timestampTz('last_milestone_updated_at');
            $table->date('execution_target_date')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE budget_schedules ADD CONSTRAINT chk_budget_schedules_quarter CHECK (target_quarter IN ('Q1','Q2','Q3','Q4'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_schedules');
    }
};
