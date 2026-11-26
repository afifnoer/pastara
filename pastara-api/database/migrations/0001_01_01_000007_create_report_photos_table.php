<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('photo_type', 20);
            $table->smallInteger('cycle')->default(1);
            $table->smallInteger('sequence')->default(1);
            $table->string('file_path', 255);
            $table->string('thumbnail_path', 255);
            $table->decimal('exif_latitude', 10, 7)->nullable();
            $table->decimal('exif_longitude', 10, 7)->nullable();
            $table->timestampTz('exif_taken_at')->nullable();
            $table->integer('distance_from_report_m')->nullable();
            $table->boolean('has_geotag_warning')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['report_id', 'cycle', 'photo_type', 'sequence'], 'idx_photos_report_cycle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_photos');
    }
};
