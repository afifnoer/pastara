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
        Schema::create('ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained('reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->smallInteger('speed_score');
            $table->smallInteger('physical_quality_score');
            $table->string('comment', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ratings ADD CONSTRAINT chk_ratings_speed_score CHECK (speed_score BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE ratings ADD CONSTRAINT chk_ratings_physical_quality_score CHECK (physical_quality_score BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
