<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $fillable = [
        'report_id',
        'cycle',
        'raised_by_user_id',
        'citizen_reason',
        'status',
        'auditor_id',
        'audit_notes',
        'reopen_sla_hours',
        'resolved_at',
    ];

    protected $attributes = [
        'status' => DisputeStatus::PENDING->value,
    ];

    protected function casts(): array
    {
        return [
            'cycle' => 'integer',
            'status' => DisputeStatus::class,
            'reopen_sla_hours' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}
