<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Village extends Model
{
    protected $fillable = [
        'district_id',
        'opd_unit_id',
        'name',
        'type',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function opdUnit(): BelongsTo
    {
        return $this->belongsTo(OpdUnit::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
