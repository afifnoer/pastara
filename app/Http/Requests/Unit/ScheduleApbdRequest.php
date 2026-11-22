<?php

declare(strict_types=1);

namespace App\Http\Requests\Unit;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ScheduleApbdRequest extends FormRequest
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
        $currentYear = CarbonImmutable::now('Asia/Jakarta')->year;

        return [
            'fiscal_year' => ['required', 'integer', "min:{$currentYear}", 'max:2035'],
            'proposal_ref_number' => ['required', 'string', 'min:3', 'max:100'],
            'target_quarter' => ['required', 'string', 'in:Q1,Q2,Q3,Q4'],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'note' => ['required', 'string', 'min:20', 'max:1000'],
            'document_proof' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
        ];
    }
}
