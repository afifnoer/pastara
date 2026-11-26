<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhotoType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportPhoto extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'report_id',
        'uploaded_by_user_id',
        'photo_type',
        'cycle',
        'sequence',
        'file_path',
        'thumbnail_path',
        'exif_latitude',
        'exif_longitude',
        'exif_taken_at',
        'distance_from_report_m',
        'has_geotag_warning',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'photo_type' => PhotoType::class,
            'cycle' => 'integer',
            'sequence' => 'integer',
            'exif_latitude' => 'float',
            'exif_longitude' => 'float',
            'exif_taken_at' => 'immutable_datetime',
            'distance_from_report_m' => 'integer',
            'has_geotag_warning' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
