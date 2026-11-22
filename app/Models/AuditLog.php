<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id',
        'actor_user_id',
        'actor_role',
        'actor_unit_name',
        'action_type',
        'from_status',
        'to_status',
        'note',
        'metadata',
        'is_public_visible',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => AuditActionType::class,
            'from_status' => ReportStatus::class,
            'to_status' => ReportStatus::class,
            'metadata' => 'array',
            'is_public_visible' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->created_at ??= now();
        });

        static::updating(function (): never {
            throw new LogicException('AuditLog records are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('AuditLog records are immutable and cannot be deleted.');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('AuditLog records are immutable and cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('AuditLog records are immutable and cannot be deleted.');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
