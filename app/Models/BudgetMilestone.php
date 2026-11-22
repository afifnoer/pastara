<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetMilestone extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'budget_schedule_id',
        'updated_by_user_id',
        'quarter_label',
        'progress_note',
        'attachment_path',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function budgetSchedule(): BelongsTo
    {
        return $this->belongsTo(BudgetSchedule::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
