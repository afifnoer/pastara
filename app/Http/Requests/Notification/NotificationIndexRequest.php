<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class NotificationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('unread_only')) {
            $raw = $this->input('unread_only');
            if (is_string($raw)) {
                $normalized = strtolower(trim($raw));
                if (in_array($normalized, ['true', '1'], true)) {
                    $this->merge(['unread_only' => true]);
                } elseif (in_array($normalized, ['false', '0'], true)) {
                    $this->merge(['unread_only' => false]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unread_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
