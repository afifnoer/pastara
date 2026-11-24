<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OpdUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OpdUnit
 */
class AdminOpdUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'description' => $this->description,
            'is_active' => $this->is_active,
            'users_count' => $this->whenCounted('users'),
            'reports_count' => $this->whenCounted('reports'),
            'cached_compliance_score' => $this->cached_compliance_score,
            'metrics_updated_at' => $this->metrics_updated_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
