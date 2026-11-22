<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnitLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpdUnit extends Model
{
    protected $fillable = [
        'code',
        'name',
        'level',
        'description',
        'is_active',
        'cached_sla_compliance',
        'cached_physical_resolution',
        'cached_dispute_rate',
        'cached_quality_index',
        'cached_compliance_score',
        'metrics_updated_at',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'level' => UnitLevel::class,
            'is_active' => 'boolean',
            'cached_sla_compliance' => 'float',
            'cached_physical_resolution' => 'float',
            'cached_dispute_rate' => 'float',
            'cached_quality_index' => 'float',
            'cached_compliance_score' => 'float',
            'metrics_updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'default_opd_unit_id');
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
