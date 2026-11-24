<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\IssueType;
use App\Enums\ReportStatus;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class AdminManagementService
{
    /**
     * Statuses that represent an active field assignment for a PETUGAS account.
     *
     * @var list<string>
     */
    private const ACTIVE_OFFICER_STATUSES = [
        ReportStatus::IN_PROGRESS->value,
        ReportStatus::REOPENED_BY_AUDIT->value,
    ];

    /**
     * Terminal statuses excluded when checking if an OPD unit still holds active tickets.
     *
     * @var list<string>
     */
    private const FINAL_REPORT_STATUSES = [
        ReportStatus::REJECTED->value,
        ReportStatus::VERIFIED_CLOSED->value,
        ReportStatus::AUTO_VERIFIED_CLOSED->value,
        ReportStatus::CLOSED_BY_AUDIT->value,
    ];

    public function __construct(
        private readonly AuditLoggerService $auditLogger,
    ) {}

    /**
     * List users with optional filters (role, opd_unit_id, is_active, search q).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listUsers(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with('opdUnit');

        if (! empty($filters['role'])) {
            $query->where('role', (string) $filters['role']);
        }

        if (isset($filters['opd_unit_id'])) {
            $query->where('opd_unit_id', (int) $filters['opd_unit_id']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['q'])) {
            $needle = '%'.addcslashes(trim((string) $filters['q']), '%_\\').'%';
            $query->where(function (Builder $sub) use ($needle): void {
                $sub->where('name', 'ILIKE', $needle)
                    ->orWhere('email', 'ILIKE', $needle);
            });
        }

        return $query->orderBy('id')->paginate(20);
    }

    /**
     * Create an internal staff account (ADMIN_UNIT, PETUGAS, or INSPEKTORAT) and record USER_CREATED audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function createUser(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $role = UserRole::from((string) $data['role']);
            $opdUnitId = isset($data['opd_unit_id']) ? (int) $data['opd_unit_id'] : null;

            $user = new User;
            $user->name = (string) $data['name'];
            $user->email = (string) $data['email'];
            $user->phone = isset($data['phone']) && $data['phone'] !== '' ? (string) $data['phone'] : null;
            $user->password = (string) $data['password'];
            $user->role = $role;
            $user->opd_unit_id = $opdUnitId;
            $user->is_active = true;
            $user->save();

            $this->auditLogger->log(
                actionType: AuditActionType::USER_CREATED,
                actor: $actor,
                note: sprintf('Akun internal %s (%s) dibuat oleh Super Admin.', $user->email, $role->value),
                metadata: [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'target_role' => $role->value,
                    'opd_unit_id' => $opdUnitId,
                ],
            );

            return $user->load('opdUnit');
        });
    }

    /**
     * Update, reset password, or deactivate an account with active-task guard and token revocation.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateUser(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data): User {
            /** @var User $lockedTarget */
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $wasActive = (bool) $lockedTarget->is_active;
            $deactivating = array_key_exists('is_active', $data) && $data['is_active'] === false;

            if ($deactivating) {
                if (! Gate::forUser($actor)->allows('deactivate', $lockedTarget)) {
                    throw new AccessDeniedHttpException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
                }

                if ($lockedTarget->role === UserRole::PETUGAS) {
                    $hasActiveTasks = Report::query()
                        ->where('assigned_officer_id', $lockedTarget->id)
                        ->whereIn('status', self::ACTIVE_OFFICER_STATUSES)
                        ->lockForUpdate()
                        ->exists();

                    if ($hasActiveTasks) {
                        throw new InvalidStateTransitionException(
                            'Petugas tidak dapat dinonaktifkan karena masih memiliki tugas lapangan aktif. Silakan alihkan tugas ke petugas lain terlebih dahulu.'
                        );
                    }
                }
            }

            $changes = [];

            if (array_key_exists('name', $data)) {
                $lockedTarget->name = (string) $data['name'];
                $changes['name'] = $lockedTarget->name;
            }

            if (array_key_exists('phone', $data)) {
                $lockedTarget->phone = $data['phone'] !== null && $data['phone'] !== ''
                    ? (string) $data['phone']
                    : null;
                $changes['phone'] = $lockedTarget->phone;
            }

            if (
                array_key_exists('opd_unit_id', $data)
                && in_array($lockedTarget->role, [UserRole::ADMIN_UNIT, UserRole::PETUGAS, UserRole::INSPEKTORAT], true)
            ) {
                $lockedTarget->opd_unit_id = $data['opd_unit_id'] !== null ? (int) $data['opd_unit_id'] : null;
                $changes['opd_unit_id'] = $lockedTarget->opd_unit_id;
            }

            $passwordReset = array_key_exists('password', $data)
                && is_string($data['password'])
                && $data['password'] !== '';

            if ($passwordReset) {
                $lockedTarget->password = $data['password'];
                $changes['password_reset'] = true;
            }

            if (array_key_exists('is_active', $data)) {
                $lockedTarget->is_active = (bool) $data['is_active'];
                $changes['is_active'] = $lockedTarget->is_active;
            }

            $lockedTarget->save();

            // SR-003 / BR-042: Revoke all Sanctum tokens immediately upon deactivation or password reset.
            if (! $lockedTarget->is_active || $passwordReset) {
                $lockedTarget->tokens()->delete();
            }

            $isNewlyDeactivated = $wasActive && ! $lockedTarget->is_active;

            $this->auditLogger->log(
                actionType: $isNewlyDeactivated ? AuditActionType::USER_DEACTIVATED : AuditActionType::USER_UPDATED,
                actor: $actor,
                note: $isNewlyDeactivated
                    ? sprintf('Akun %s dinonaktifkan dan seluruh sesi dicabut.', $lockedTarget->email)
                    : sprintf('Akun %s diperbarui oleh Super Admin.', $lockedTarget->email),
                metadata: [
                    'target_user_id' => $lockedTarget->id,
                    'target_email' => $lockedTarget->email,
                    'changes' => $changes,
                ],
            );

            return $lockedTarget->load('opdUnit');
        });
    }

    /**
     * List all OPD/Desa/Kelurahan/Inspektorat units with optional filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, OpdUnit>
     */
    public function listOpdUnits(array $filters): Collection
    {
        $query = OpdUnit::query()->withCount(['users', 'reports']);

        if (! empty($filters['level'])) {
            $query->where('level', (string) $filters['level']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['q'])) {
            $needle = '%'.addcslashes(trim((string) $filters['q']), '%_\\').'%';
            $query->where(function (Builder $sub) use ($needle): void {
                $sub->where('name', 'ILIKE', $needle)
                    ->orWhere('code', 'ILIKE', $needle);
            });
        }

        return $query->orderBy('level')->orderBy('name')->get();
    }

    /**
     * Create a new OPD / Desa / Kelurahan / Inspektorat unit.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOpdUnit(User $actor, array $data): OpdUnit
    {
        return DB::transaction(function () use ($actor, $data): OpdUnit {
            $unit = OpdUnit::create([
                'code' => (string) $data['code'],
                'name' => (string) $data['name'],
                'level' => UnitLevel::from((string) $data['level']),
                'description' => isset($data['description']) && $data['description'] !== ''
                    ? (string) $data['description']
                    : null,
                'is_active' => true,
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::UNIT_UPDATED,
                actor: $actor,
                note: sprintf('Unit kerja baru %s (%s) ditambahkan oleh Super Admin.', $unit->name, $unit->code),
                metadata: [
                    'action' => 'created',
                    'opd_unit_id' => $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                    'level' => $unit->level->value,
                ],
            );

            return $unit->loadCount(['users', 'reports']);
        });
    }

    /**
     * Update or deactivate an OPD unit (rejects 409 if the unit still has active non-final tickets).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateOpdUnit(User $actor, OpdUnit $opdUnit, array $data): OpdUnit
    {
        return DB::transaction(function () use ($actor, $opdUnit, $data): OpdUnit {
            /** @var OpdUnit $lockedUnit */
            $lockedUnit = OpdUnit::query()->whereKey($opdUnit->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
                $hasActiveReports = Report::query()
                    ->where('opd_unit_id', $lockedUnit->id)
                    ->whereNotIn('status', self::FINAL_REPORT_STATUSES)
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveReports) {
                    throw new InvalidStateTransitionException(
                        'Unit kerja tidak dapat dinonaktifkan karena masih memiliki tiket laporan aktif.'
                    );
                }
            }

            $changes = [];

            if (array_key_exists('name', $data)) {
                $lockedUnit->name = (string) $data['name'];
                $changes['name'] = $lockedUnit->name;
            }

            if (array_key_exists('description', $data)) {
                $lockedUnit->description = $data['description'] !== null && $data['description'] !== ''
                    ? (string) $data['description']
                    : null;
                $changes['description'] = $lockedUnit->description;
            }

            if (array_key_exists('is_active', $data)) {
                $lockedUnit->is_active = (bool) $data['is_active'];
                $changes['is_active'] = $lockedUnit->is_active;
            }

            $lockedUnit->save();

            $this->auditLogger->log(
                actionType: AuditActionType::UNIT_UPDATED,
                actor: $actor,
                note: sprintf('Unit kerja %s (%s) diperbarui oleh Super Admin.', $lockedUnit->name, $lockedUnit->code),
                metadata: [
                    'action' => 'updated',
                    'opd_unit_id' => $lockedUnit->id,
                    'code' => $lockedUnit->code,
                    'changes' => $changes,
                ],
            );

            return $lockedUnit->loadCount(['users', 'reports']);
        });
    }

    /**
     * List all problem categories with default OPD unit eager-loaded.
     *
     * @return Collection<int, Category>
     */
    public function listCategories(): Collection
    {
        return Category::query()
            ->with('defaultOpdUnit')
            ->orderBy('code')
            ->get();
    }

    /**
     * Update category parameters (BR-004: strictly affects future tickets; existing report snapshots remain intact).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(User $actor, Category $category, array $data): Category
    {
        return DB::transaction(function () use ($actor, $category, $data): Category {
            /** @var Category $lockedCategory */
            $lockedCategory = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            $before = [
                'default_opd_unit_id' => $lockedCategory->default_opd_unit_id,
                'default_sla_hours' => $lockedCategory->default_sla_hours,
                'issue_type' => $lockedCategory->issue_type->value,
                'duplicate_radius_m' => $lockedCategory->duplicate_radius_m,
                'geotag_tolerance_m' => $lockedCategory->geotag_tolerance_m,
                'recommend_secret' => $lockedCategory->recommend_secret,
                'is_active' => $lockedCategory->is_active,
            ];

            if (array_key_exists('default_opd_unit_id', $data)) {
                $lockedCategory->default_opd_unit_id = $data['default_opd_unit_id'] !== null
                    ? (int) $data['default_opd_unit_id']
                    : null;
            }

            if (array_key_exists('default_sla_hours', $data)) {
                $lockedCategory->default_sla_hours = (int) $data['default_sla_hours'];
            }

            if (array_key_exists('issue_type', $data)) {
                $lockedCategory->issue_type = IssueType::from((string) $data['issue_type']);
            }

            if (array_key_exists('duplicate_radius_m', $data)) {
                $lockedCategory->duplicate_radius_m = (int) $data['duplicate_radius_m'];
            }

            if (array_key_exists('geotag_tolerance_m', $data)) {
                $lockedCategory->geotag_tolerance_m = (int) $data['geotag_tolerance_m'];
            }

            if (array_key_exists('recommend_secret', $data)) {
                $lockedCategory->recommend_secret = (bool) $data['recommend_secret'];
            }

            if (array_key_exists('is_active', $data)) {
                $lockedCategory->is_active = (bool) $data['is_active'];
            }

            $lockedCategory->save();

            $after = [
                'default_opd_unit_id' => $lockedCategory->default_opd_unit_id,
                'default_sla_hours' => $lockedCategory->default_sla_hours,
                'issue_type' => $lockedCategory->issue_type->value,
                'duplicate_radius_m' => $lockedCategory->duplicate_radius_m,
                'geotag_tolerance_m' => $lockedCategory->geotag_tolerance_m,
                'recommend_secret' => $lockedCategory->recommend_secret,
                'is_active' => $lockedCategory->is_active,
            ];

            $this->auditLogger->log(
                actionType: AuditActionType::CATEGORY_UPDATED,
                actor: $actor,
                note: sprintf(
                    'Parameter kategori %s (%s) diperbarui (hanya berlaku untuk tiket baru).',
                    $lockedCategory->name,
                    $lockedCategory->code
                ),
                metadata: [
                    'category_id' => $lockedCategory->id,
                    'code' => $lockedCategory->code,
                    'before' => $before,
                    'after' => $after,
                ],
            );

            return $lockedCategory->load('defaultOpdUnit');
        });
    }

    /**
     * List all districts with their villages and assigned village OPD units (zero lazy loading).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLocations(): array
    {
        $districts = District::query()
            ->with(['villages' => static function ($query): void {
                $query->with('opdUnit')->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return $districts->map(static fn (District $district): array => [
            'id' => $district->id,
            'code' => $district->code,
            'name' => $district->name,
            'villages' => $district->villages->map(static fn (Village $village): array => [
                'id' => $village->id,
                'district_id' => $village->district_id,
                'opd_unit_id' => $village->opd_unit_id,
                'opd_unit' => $village->opdUnit !== null ? [
                    'id' => $village->opdUnit->id,
                    'code' => $village->opdUnit->code,
                    'name' => $village->opdUnit->name,
                    'level' => $village->opdUnit->level->value,
                ] : null,
                'name' => $village->name,
                'village_type' => $village->type,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Create a district or village record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createLocation(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $type = (string) $data['type'];

            if ($type === 'district') {
                $district = District::create([
                    'code' => (string) $data['code'],
                    'name' => (string) $data['name'],
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::UNIT_UPDATED,
                    actor: $actor,
                    note: sprintf('Kecamatan baru %s (%s) ditambahkan.', $district->name, $district->code),
                    metadata: [
                        'location_type' => 'district',
                        'action' => 'created',
                        'district_id' => $district->id,
                        'code' => $district->code,
                        'name' => $district->name,
                    ],
                );

                return [
                    'type' => 'district',
                    'id' => $district->id,
                    'code' => $district->code,
                    'name' => $district->name,
                ];
            }

            $village = Village::create([
                'district_id' => (int) $data['district_id'],
                'opd_unit_id' => isset($data['opd_unit_id']) ? (int) $data['opd_unit_id'] : null,
                'name' => (string) $data['name'],
                'type' => (string) $data['village_type'],
            ]);

            $village->load(['district', 'opdUnit']);

            $this->auditLogger->log(
                actionType: AuditActionType::UNIT_UPDATED,
                actor: $actor,
                note: sprintf('Wilayah %s %s ditambahkan.', $village->type, $village->name),
                metadata: [
                    'location_type' => 'village',
                    'action' => 'created',
                    'village_id' => $village->id,
                    'district_id' => $village->district_id,
                    'opd_unit_id' => $village->opd_unit_id,
                    'name' => $village->name,
                    'village_type' => $village->type,
                ],
            );

            return [
                'type' => 'village',
                'id' => $village->id,
                'district_id' => $village->district_id,
                'district_name' => $village->district?->name,
                'opd_unit_id' => $village->opd_unit_id,
                'opd_unit' => $village->opdUnit !== null ? [
                    'id' => $village->opdUnit->id,
                    'code' => $village->opdUnit->code,
                    'name' => $village->opdUnit->name,
                ] : null,
                'name' => $village->name,
                'village_type' => $village->type,
            ];
        });
    }

    /**
     * Update an existing district or village record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateLocation(User $actor, string $type, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $type, $id, $data): array {
            if ($type === 'district') {
                /** @var District $district */
                $district = District::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if (array_key_exists('name', $data)) {
                    $district->name = (string) $data['name'];
                }

                if (array_key_exists('code', $data)) {
                    $district->code = (string) $data['code'];
                }

                $district->save();

                $this->auditLogger->log(
                    actionType: AuditActionType::UNIT_UPDATED,
                    actor: $actor,
                    note: sprintf('Data Kecamatan %s (%s) diperbarui.', $district->name, $district->code),
                    metadata: [
                        'location_type' => 'district',
                        'action' => 'updated',
                        'district_id' => $district->id,
                        'code' => $district->code,
                        'name' => $district->name,
                    ],
                );

                return [
                    'type' => 'district',
                    'id' => $district->id,
                    'code' => $district->code,
                    'name' => $district->name,
                ];
            }

            /** @var Village $village */
            $village = Village::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $data)) {
                $village->name = (string) $data['name'];
            }

            if (array_key_exists('district_id', $data)) {
                $village->district_id = (int) $data['district_id'];
            }

            if (array_key_exists('opd_unit_id', $data)) {
                $village->opd_unit_id = $data['opd_unit_id'] !== null ? (int) $data['opd_unit_id'] : null;
            }

            if (array_key_exists('village_type', $data)) {
                $village->type = (string) $data['village_type'];
            }

            $village->save();
            $village->load(['district', 'opdUnit']);

            $this->auditLogger->log(
                actionType: AuditActionType::UNIT_UPDATED,
                actor: $actor,
                note: sprintf('Data %s %s diperbarui.', $village->type, $village->name),
                metadata: [
                    'location_type' => 'village',
                    'action' => 'updated',
                    'village_id' => $village->id,
                    'district_id' => $village->district_id,
                    'opd_unit_id' => $village->opd_unit_id,
                    'name' => $village->name,
                    'village_type' => $village->type,
                ],
            );

            return [
                'type' => 'village',
                'id' => $village->id,
                'district_id' => $village->district_id,
                'district_name' => $village->district?->name,
                'opd_unit_id' => $village->opd_unit_id,
                'opd_unit' => $village->opdUnit !== null ? [
                    'id' => $village->opdUnit->id,
                    'code' => $village->opdUnit->code,
                    'name' => $village->opdUnit->name,
                ] : null,
                'name' => $village->name,
                'village_type' => $village->type,
            ];
        });
    }

    /**
     * Read-only paginated system audit log viewer for SUPER_ADMIN and INSPEKTORAT.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listAuditLogs(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->with([
                'actor:id,name,email,role',
                'report:id,ticket_code,title',
            ]);

        if (isset($filters['report_id'])) {
            $query->where('report_id', (int) $filters['report_id']);
        }

        if (! empty($filters['actor_role'])) {
            $query->where('actor_role', (string) $filters['actor_role']);
        }

        if (! empty($filters['action_type'])) {
            $query->where('action_type', (string) $filters['action_type']);
        }

        if (! empty($filters['from'])) {
            try {
                $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['from'], 'Asia/Jakarta')
                    ->startOfDay()
                    ->utc();
                $query->where('created_at', '>=', $fromUtc);
            } catch (Throwable) {
                // Validated by AuditLogIndexRequest; safeguard against malformed dates.
            }
        }

        if (! empty($filters['to'])) {
            try {
                $toUtc = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['to'], 'Asia/Jakarta')
                    ->endOfDay()
                    ->utc();
                $query->where('created_at', '<=', $toUtc);
            } catch (Throwable) {
                // Validated by AuditLogIndexRequest; safeguard against malformed dates.
            }
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
    }
}
