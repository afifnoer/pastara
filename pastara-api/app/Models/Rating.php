<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'report_id',
        'user_id',
        'speed_score',
        'physical_quality_score',
        'comment',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'speed_score' => 'integer',
            'physical_quality_score' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
