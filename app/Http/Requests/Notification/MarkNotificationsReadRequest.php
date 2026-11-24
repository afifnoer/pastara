<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notification_ids' => ['nullable', 'array', 'max:100'],
            'notification_ids.*' => ['bail', 'required', 'integer', 'min:1'],
        ];
    }
}
