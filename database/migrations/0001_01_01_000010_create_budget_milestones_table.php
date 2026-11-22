<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_schedule_id')->constrained('budget_schedules')->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('quarter_label', 15);
            $table->text('progress_note');
            $table->string('attachment_path', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_milestones');
    }
};
