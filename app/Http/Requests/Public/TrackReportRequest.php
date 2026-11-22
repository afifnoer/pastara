<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class TrackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('ticket_code')) {
            $this->merge([
                'ticket_code' => strtoupper(trim((string) $this->input('ticket_code'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket_code' => ['required', 'string', 'max:20'],
            'secret_token' => ['nullable', 'string', 'max:20'],
        ];
    }
}
