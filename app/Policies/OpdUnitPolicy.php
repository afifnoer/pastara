<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\OpdUnit;
use App\Models\User;

class OpdUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::SUPER_ADMIN;
    }

    public function view(User $user, OpdUnit $opdUnit): bool
    {
        return $user->role === UserRole::SUPER_ADMIN;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SUPER_ADMIN;
    }

    public function update(User $user, OpdUnit $opdUnit): bool
    {
        return $user->role === UserRole::SUPER_ADMIN;
    }

    public function deactivate(User $user, OpdUnit $opdUnit): bool
    {
        return $user->role === UserRole::SUPER_ADMIN;
    }
}
