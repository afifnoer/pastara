<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Report extends Model
{
    protected $fillable = [
        'ticket_code',
        'reporter_id',
        'category_id',
        'opd_unit_id',
        'assigned_officer_id',
        'district_id',
        'village_id',
        'title',
        'description_sanitized',
        'latitude',
        'longitude',
        'address_detail',
        'is_secret',
        'secret_token_hash',
        'resolution_track',
        'status',
        'current_cycle',
        'issue_type_snapshot',
        'duplicate_radius_snapshot_m',
        'geotag_tolerance_snapshot_m',
        'sla_hours_snapshot',
        'sla_started_at',
        'sla_cycle_started_at',
        'sla_paused_at',
        'sla_deadline_at',
        'sla_stopped_at',
        'sla_warning_notified_at',
        'signoff_started_at',
        'signoff_deadline_at',
        'signoff_reminder_sent_at',
        'is_sla_overdue',
        'is_milestone_overdue',
        'is_late',
        'geotag_warning',
        'geotag_distance_m',
        'officer_action_note',
        'rejection_reason_code',
        'rejection_note',
        'upvote_count',
    ];

    protected $hidden = [
        'secret_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_secret' => 'boolean',
            'resolution_track' => ResolutionTrack::class,
            'status' => ReportStatus::class,
            'current_cycle' => 'integer',
            'issue_type_snapshot' => IssueType::class,
            'duplicate_radius_snapshot_m' => 'integer',
            'geotag_tolerance_snapshot_m' => 'integer',
            'sla_hours_snapshot' => 'integer',
            'sla_started_at' => 'immutable_datetime',
            'sla_cycle_started_at' => 'immutable_datetime',
            'sla_paused_at' => 'immutable_datetime',
            'sla_deadline_at' => 'immutable_datetime',
            'sla_stopped_at' => 'immutable_datetime',
            'sla_warning_notified_at' => 'immutable_datetime',
            'signoff_started_at' => 'immutable_datetime',
            'signoff_deadline_at' => 'immutable_datetime',
            'signoff_reminder_sent_at' => 'immutable_datetime',
            'is_sla_overdue' => 'boolean',
            'is_milestone_overdue' => 'boolean',
            'is_late' => 'boolean',
            'geotag_warning' => 'boolean',
            'geotag_distance_m' => 'integer',
            'rejection_reason_code' => RejectionReasonCode::class,
            'upvote_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public static function hashSecretToken(string $plainToken): string
    {
        return hash_hmac('sha256', trim($plainToken), (string) config('app.key'));
    }

    public function verifySecretToken(?string $plainToken): bool
    {
        if (! $this->is_secret || $this->secret_token_hash === null || $plainToken === null || trim($plainToken) === '') {
            return false;
        }

        return hash_equals($this->secret_token_hash, self::hashSecretToken($plainToken));
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function opdUnit(): BelongsTo
    {
        return $this->belongsTo(OpdUnit::class);
    }

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReportPhoto::class);
    }

    public function upvotes(): HasMany
    {
        return $this->hasMany(ReportUpvote::class);
    }

    public function budgetSchedule(): HasOne
    {
        return $this->hasOne(BudgetSchedule::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
