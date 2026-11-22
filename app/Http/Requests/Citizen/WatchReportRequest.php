<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use Illuminate\Foundation\Http\FormRequest;

class WatchReportRequest extends FormRequest
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
            'is_watching' => ['required', 'boolean'],
        ];
    }
}
