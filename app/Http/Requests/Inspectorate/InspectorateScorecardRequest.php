<?php

declare(strict_types=1);

namespace App\Http\Requests\Inspectorate;

use Illuminate\Foundation\Http\FormRequest;

class InspectorateScorecardRequest extends FormRequest
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
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2025,2035'],
            'sort' => ['nullable', 'string', 'in:compliance_asc,compliance_desc,overdue_desc,dispute_desc,sla_asc,name_asc'],
        ];
    }
}
