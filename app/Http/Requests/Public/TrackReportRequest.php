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
        $rawCode = $this->input('ticket_code');
        if (is_string($rawCode)) {
            $this->merge([
                'ticket_code' => strtoupper(trim($rawCode)),
            ]);
        }

        $rawToken = $this->input('secret_token');
        if (is_string($rawToken)) {
            $this->merge([
                'secret_token' => strtoupper(trim($rawToken)),
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
