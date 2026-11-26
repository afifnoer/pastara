<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// M07 — 4 Automated Scheduler Commands (SRS Bab 6.2)
Schedule::command('pastara:evaluate-sla')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('pastara:auto-verify-signoff')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('pastara:evaluate-apbd-milestones')
    ->dailyAt('00:05')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('pastara:recalculate-scorecard')
    ->everyFiveMinutes()
    ->withoutOverlapping();
