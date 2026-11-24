<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AuditActionType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::SUPER_ADMIN, UserRole::INSPEKTORAT], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_id' => ['nullable', 'bail', 'integer', 'min:1', 'exists:reports,id'],
            'actor_role' => [
                'nullable',
                'string',
                Rule::in([
                    UserRole::WARGA->value,
                    UserRole::ADMIN_UNIT->value,
                    UserRole::PETUGAS->value,
                    UserRole::INSPEKTORAT->value,
                    UserRole::SUPER_ADMIN->value,
                    'SYSTEM',
                ]),
            ],
            'action_type' => ['nullable', 'string', Rule::enum(AuditActionType::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
