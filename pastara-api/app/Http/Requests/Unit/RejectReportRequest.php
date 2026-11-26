<?php

declare(strict_types=1);

namespace App\Http\Requests\Unit;

use App\Enums\RejectionReasonCode;
use App\Models\Report;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RejectReportRequest extends FormRequest
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
        return [
            'reason_code' => ['required', 'string', Rule::enum(RejectionReasonCode::class)],
            'note' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }
}
