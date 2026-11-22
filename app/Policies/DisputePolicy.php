<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DisputeStatus;
use App\Enums\UserRole;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DisputePolicy
{
    public function resolve(User $user, Dispute $dispute): Response
    {
        if ($user->role !== UserRole::INSPEKTORAT) {
            return Response::deny('Hanya auditor Inspektorat yang berhak memutus sengketa.');
        }

        return $dispute->status === DisputeStatus::PENDING
            ? Response::allow()
            : Response::denyWithStatus(409, 'Sengketa ini telah diputus sebelumnya.');
    }
}
