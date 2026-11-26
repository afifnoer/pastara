<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opd_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('level', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('cached_sla_compliance', 5, 2)->nullable();
            $table->decimal('cached_physical_resolution', 5, 2)->nullable();
            $table->decimal('cached_dispute_rate', 5, 2)->nullable();
            $table->decimal('cached_quality_index', 3, 2)->nullable();
            $table->decimal('cached_compliance_score', 5, 2)->nullable();
            $table->timestampTz('metrics_updated_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opd_units');
    }
};
