<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => strtolower(trim($this->input('email'))),
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'regex:/[A-Za-z]/', 'regex:/\d/'],
            'role' => [
                'required',
                'string',
                Rule::in([
                    UserRole::ADMIN_UNIT->value,
                    UserRole::PETUGAS->value,
                    UserRole::INSPEKTORAT->value,
                ]),
            ],
            'opd_unit_id' => [
                'required_if:role,ADMIN_UNIT,PETUGAS',
                'nullable',
                'bail',
                'integer',
                'min:1',
                Rule::exists('opd_units', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Alamat email sudah terdaftar pada sistem.',
            'password.regex' => 'Kata sandi harus mengandung minimal satu huruf dan satu angka.',
            'role.in' => 'Peran yang diizinkan hanya ADMIN_UNIT, PETUGAS, atau INSPEKTORAT.',
            'opd_unit_id.required_if' => 'Unit kerja wajib dipilih untuk akun Admin Unit dan Petugas.',
            'opd_unit_id.exists' => 'Unit kerja tidak ditemukan atau sedang nonaktif.',
        ];
    }
}
