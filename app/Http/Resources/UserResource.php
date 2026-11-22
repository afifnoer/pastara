<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->relationLoaded('opdUnit') ? $this->opdUnit : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'opd_unit_id' => $this->opd_unit_id,
            'opd_unit' => $unit !== null ? [
                'id' => $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'level' => $unit->level->value,
            ] : null,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
