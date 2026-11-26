<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\IssueType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_opd_unit_id' => [
                'sometimes',
                'nullable',
                'bail',
                'integer',
                'min:1',
                Rule::exists('opd_units', 'id')->where('is_active', true),
            ],
            'default_sla_hours' => ['sometimes', 'required', 'integer', Rule::in([24, 48, 72])],
            'issue_type' => ['sometimes', 'required', 'string', Rule::enum(IssueType::class)],
            'duplicate_radius_m' => ['sometimes', 'required', 'integer', 'min:50', 'max:999'],
            'geotag_tolerance_m' => ['sometimes', 'required', 'integer', 'min:25', 'max:1000'],
            'recommend_secret' => ['sometimes', 'required', 'boolean'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_opd_unit_id.exists' => 'Unit kerja tujuan default tidak ditemukan atau sedang nonaktif.',
            'default_sla_hours.in' => 'Durasi SLA default harus 24, 48, atau 72 jam.',
            'duplicate_radius_m.min' => 'Radius deteksi duplikat minimal 50 meter.',
            'duplicate_radius_m.max' => 'Radius deteksi duplikat maksimal 999 meter.',
            'geotag_tolerance_m.min' => 'Toleransi geotag minimal 25 meter.',
            'geotag_tolerance_m.max' => 'Toleransi geotag maksimal 1000 meter.',
        ];
    }
}
