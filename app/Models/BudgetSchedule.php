<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetSchedule extends Model
{
    protected $fillable = [
        'report_id',
        'fiscal_year',
        'proposal_ref_number',
        'target_quarter',
        'estimated_budget',
        'document_proof_path',
        'document_due_at',
        'document_uploaded_at',
        'last_milestone_updated_at',
        'execution_target_date',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'estimated_budget' => 'decimal:2',
            'document_due_at' => 'immutable_datetime',
            'document_uploaded_at' => 'immutable_datetime',
            'last_milestone_updated_at' => 'immutable_datetime',
            'execution_target_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(BudgetMilestone::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
