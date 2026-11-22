<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+62|62|0)8[1-9][0-9]{7,11}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Email sudah terdaftar.',
            'phone.regex' => 'Format nomor HP Indonesia tidak valid (gunakan awalan 08, 628, atau +628).',
            'password.regex' => 'Kata sandi harus mengandung kombinasi huruf dan angka.',
        ];
    }
}
