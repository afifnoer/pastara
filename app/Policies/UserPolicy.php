<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SUPER_ADMIN;
    }

    public function view(User $user, User $target): bool
    {
        return $user->is_active && $user->role === UserRole::SUPER_ADMIN;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SUPER_ADMIN;
    }

    public function update(User $user, User $target): bool
    {
        return $user->is_active && $user->role === UserRole::SUPER_ADMIN;
    }

    public function deactivate(User $user, User $target): bool
    {
        return $user->is_active
            && $user->role === UserRole::SUPER_ADMIN
            && (int) $user->id !== (int) $target->id;
    }
}
