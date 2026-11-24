<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SUPER_ADMIN;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active') && is_string($this->input('is_active'))) {
            $normalized = strtolower(trim($this->input('is_active')));
            if (in_array($normalized, ['true', '1'], true)) {
                $this->merge(['is_active' => true]);
            } elseif (in_array($normalized, ['false', '0'], true)) {
                $this->merge(['is_active' => false]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['nullable', 'string', Rule::enum(UserRole::class)],
            'opd_unit_id' => ['nullable', 'bail', 'integer', 'min:1', 'exists:opd_units,id'],
            'is_active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
