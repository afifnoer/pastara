<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if (is_string($this->input('village_type'))) {
            $this->merge(['village_type' => strtoupper(trim($this->input('village_type')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = (string) $this->route('type');
        $id = (int) $this->route('id');

        if ($type === 'district') {
            return [
                'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
                'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('districts', 'code')->ignore($id)],
            ];
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'district_id' => ['sometimes', 'required', 'bail', 'integer', 'min:1', 'exists:districts,id'],
            'opd_unit_id' => [
                'sometimes',
                'nullable',
                'bail',
                'integer',
                'min:1',
                Rule::exists('opd_units', 'id')->where('is_active', true),
            ],
            'village_type' => ['sometimes', 'required', 'string', Rule::in(['DESA', 'KELURAHAN'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode kecamatan sudah digunakan oleh kecamatan lain.',
            'opd_unit_id.exists' => 'Unit kerja tidak ditemukan atau sedang nonaktif.',
        ];
    }
}
