<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScorecardCalculatorService;
use Illuminate\Console\Command;

class RecalculateScorecardCommand extends Command
{
    protected $signature = 'pastara:recalculate-scorecard';

    protected $description = 'Perbarui cache metrik rapor kepatuhan seluruh unit kerja pada tabel opd_units (FR-008, BR-033).';

    public function handle(ScorecardCalculatorService $calculator): int
    {
        $results = $calculator->recalculateAllUnits();

        $this->info("Scorecard cache updated for {$results->count()} active OPD unit(s).");

        return self::SUCCESS;
    }
}
