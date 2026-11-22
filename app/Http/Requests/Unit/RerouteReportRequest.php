<?php

declare(strict_types=1);

namespace App\Http\Requests\Unit;

use App\Models\Report;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RerouteReportRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $report = $this->route('report');

        return $report instanceof Report
            ? Gate::forUser($this->user())->inspect('manageUnit', $report)
            : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currentUnitId = (int) $this->user()?->opd_unit_id;

        return [
            'target_opd_unit_id' => [
                'required',
                'integer',
                Rule::exists('opd_units', 'id')->where('is_active', true),
                Rule::notIn([$currentUnitId]),
            ],
            'new_category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            'note' => ['required', 'string', 'min:20', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_opd_unit_id.not_in' => 'Unit tujuan harus berbeda dari unit kerja saat ini.',
        ];
    }
}
