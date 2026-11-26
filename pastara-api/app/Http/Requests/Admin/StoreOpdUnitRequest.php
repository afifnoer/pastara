<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UnitLevel;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpdUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge([
                'code' => strtoupper(trim($this->input('code'))),
            ]);
        }

        if (is_string($this->input('name'))) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:30', 'regex:/^[A-Z0-9_-]+$/', 'unique:opd_units,code'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'level' => ['required', 'string', Rule::enum(UnitLevel::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode unit kerja sudah terdaftar.',
            'code.regex' => 'Kode unit kerja hanya boleh berisi huruf kapital, angka, garis bawah, atau tanda hubung.',
        ];
    }
}
