<?php

declare(strict_types=1);

namespace App\Http\Requests\Inspectorate;

use App\Models\Dispute;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ResolveDisputeRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $dispute = $this->route('dispute');

        return $dispute instanceof Dispute
            ? Gate::forUser($this->user())->inspect('resolve', $dispute)
            : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:REOPENED,CLOSED'],
            'audit_notes' => ['required', 'string', 'min:20', 'max:2000'],
            'reopen_sla_hours' => ['required_if:decision,REOPENED', 'nullable', 'integer', 'in:24,48,72'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.in' => 'Putusan sidang audit harus berupa REOPENED atau CLOSED.',
            'audit_notes.min' => 'Catatan hasil sidang audit minimal 20 karakter.',
            'reopen_sla_hours.required_if' => 'Tenggat pengerjaan ulang wajib dipilih (24, 48, atau 72 jam) untuk putusan REOPENED.',
            'reopen_sla_hours.in' => 'Tenggat pengerjaan ulang wajib 24, 48, atau 72 jam.',
        ];
    }
}
