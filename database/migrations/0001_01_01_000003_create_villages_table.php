<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->foreignId('opd_unit_id')->nullable()->constrained('opd_units')->nullOnDelete();
            $table->string('name', 100);
            $table->string('type', 15)->default('DESA');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages');
    }
};
