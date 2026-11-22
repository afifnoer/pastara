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
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('reports')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 150);
            $table->text('message');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_notifications_user_unread ON notifications (user_id, read_at, created_at DESC)');
        } else {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->index(['user_id', 'read_at', 'created_at'], 'idx_notifications_user_unread');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
