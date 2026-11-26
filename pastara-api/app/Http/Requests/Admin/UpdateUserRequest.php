<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    protected function prepareForValidation(): void
    {
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'opd_unit_id' => [
                'sometimes',
                'nullable',
                'bail',
                'integer',
                'min:1',
                Rule::exists('opd_units', 'id')->where('is_active', true),
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:128', 'regex:/[A-Za-z]/', 'regex:/\d/'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User|null $target */
                $target = $this->route('user');

                if (
                    $target instanceof User
                    && in_array($target->role, [UserRole::ADMIN_UNIT, UserRole::PETUGAS], true)
                    && $this->exists('opd_unit_id')
                    && $this->input('opd_unit_id') === null
                ) {
                    $validator->errors()->add(
                        'opd_unit_id',
                        'Unit kerja wajib diisi untuk akun Admin Unit dan Petugas.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.regex' => 'Kata sandi harus mengandung minimal satu huruf dan satu angka.',
            'opd_unit_id.exists' => 'Unit kerja tidak ditemukan atau sedang nonaktif.',
        ];
    }
}
