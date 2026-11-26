<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class AdminCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->relationLoaded('defaultOpdUnit') ? $this->defaultOpdUnit : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'default_opd_unit_id' => $this->default_opd_unit_id,
            'default_opd_unit' => $unit !== null ? [
                'id' => $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'level' => $unit->level->value,
            ] : null,
            'route_by_village' => $this->route_by_village,
            'default_sla_hours' => $this->default_sla_hours,
            'issue_type' => $this->issue_type->value,
            'issue_type_label' => $this->issue_type->label(),
            'duplicate_radius_m' => $this->duplicate_radius_m,
            'geotag_tolerance_m' => $this->geotag_tolerance_m,
            'recommend_secret' => $this->recommend_secret,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
