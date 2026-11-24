<?php

declare(strict_types=1);

namespace App\Http\Requests\Inspectorate;

use Illuminate\Foundation\Http\FormRequest;

class InspectorateReportsRequest extends FormRequest
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
            'tab' => ['nullable', 'string', 'in:sla_overdue,milestone_overdue,disputed,all'],
            'opd_unit_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:opd_units,id'],
            'category_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:categories,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
