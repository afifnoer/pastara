<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->smallInteger('cycle');
            $table->foreignId('raised_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('citizen_reason');
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('auditor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('audit_notes')->nullable();
            $table->integer('reopen_sla_hours')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['report_id', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
