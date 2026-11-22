<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
