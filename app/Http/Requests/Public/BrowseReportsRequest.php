<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrowseReportsRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', 'string', Rule::enum(ReportStatus::class)],
            'opd_unit_id' => ['nullable', 'integer', 'exists:opd_units,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'overdue_only' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:latest,upvotes,sla'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
