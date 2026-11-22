<?php

declare(strict_types=1);

namespace App\Http\Requests\Unit;

use Illuminate\Foundation\Http\FormRequest;

class UnitReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('overdue_only')) {
            $raw = $this->input('overdue_only');
            if (is_string($raw)) {
                $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $this->merge(['overdue_only' => $parsed]);
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
            'tab' => ['nullable', 'string', 'in:verification,in_progress,apbd,sign_off,disputed,closed,rejected'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'overdue_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
