<?php

declare(strict_types=1);

namespace App\Http\Requests\Unit;

use App\Models\Report;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreMilestoneRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $report = $this->route('report');

        return $report instanceof Report
            ? Gate::forUser($this->user())->inspect('manageApbd', $report)
            : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quarter_label' => ['required', 'string', 'max:15', 'regex:/^\d{4}-Q[1-4]$/'],
            'progress_note' => ['required', 'string', 'min:20', 'max:1000'],
            'document_proof' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'attachment' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quarter_label.regex' => 'Format label kuartal harus YYYY-Q1 s.d. YYYY-Q4 (contoh: 2027-Q1).',
        ];
    }
}
