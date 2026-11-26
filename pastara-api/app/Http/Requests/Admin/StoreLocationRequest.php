<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => strtolower(trim($this->input('type')))]);
        }

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
        return [
            'type' => ['required', 'string', Rule::in(['district', 'village'])],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => ['required_if:type,district', 'nullable', 'string', 'max:20', 'unique:districts,code'],
            'district_id' => ['required_if:type,village', 'nullable', 'bail', 'integer', 'min:1', 'exists:districts,id'],
            'opd_unit_id' => [
                'nullable',
                'bail',
                'integer',
                'min:1',
                Rule::exists('opd_units', 'id')->where('is_active', true),
            ],
            'village_type' => ['required_if:type,village', 'nullable', 'string', Rule::in(['DESA', 'KELURAHAN'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Tipe wilayah harus district atau village.',
            'code.required_if' => 'Kode kecamatan wajib diisi saat menambah kecamatan.',
            'code.unique' => 'Kode kecamatan sudah terdaftar.',
            'district_id.required_if' => 'Kecamatan induk wajib diisi saat menambah desa/kelurahan.',
            'village_type.required_if' => 'Jenis wilayah desa/kelurahan wajib diisi (DESA atau KELURAHAN).',
            'opd_unit_id.exists' => 'Unit kerja tidak ditemukan atau sedang nonaktif.',
        ];
    }
}
