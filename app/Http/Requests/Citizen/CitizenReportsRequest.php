<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CitizenReportsRequest extends FormRequest
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
            'tab' => ['nullable', 'string', 'in:mine,watching'],
            'status' => ['nullable', 'string', Rule::enum(ReportStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
