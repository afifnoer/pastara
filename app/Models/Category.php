<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'default_opd_unit_id',
        'route_by_village',
        'default_sla_hours',
        'issue_type',
        'duplicate_radius_m',
        'geotag_tolerance_m',
        'recommend_secret',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'route_by_village' => 'boolean',
            'default_sla_hours' => 'integer',
            'issue_type' => IssueType::class,
            'duplicate_radius_m' => 'integer',
            'geotag_tolerance_m' => 'integer',
            'recommend_secret' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function defaultOpdUnit(): BelongsTo
    {
        return $this->belongsTo(OpdUnit::class, 'default_opd_unit_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
