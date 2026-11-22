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
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('default_opd_unit_id')->nullable()->constrained('opd_units')->restrictOnDelete();
            $table->boolean('route_by_village')->default(false);
            $table->integer('default_sla_hours');
            $table->string('issue_type', 15);
            $table->integer('duplicate_radius_m');
            $table->integer('geotag_tolerance_m');
            $table->boolean('recommend_secret')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories ADD CONSTRAINT chk_categories_sla_hours CHECK (default_sla_hours IN (24, 48, 72))');
            DB::statement('ALTER TABLE categories ADD CONSTRAINT chk_categories_duplicate_radius CHECK (duplicate_radius_m BETWEEN 50 AND 999)');
            DB::statement('ALTER TABLE categories ADD CONSTRAINT chk_categories_geotag_tolerance CHECK (geotag_tolerance_m BETWEEN 25 AND 1000)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
