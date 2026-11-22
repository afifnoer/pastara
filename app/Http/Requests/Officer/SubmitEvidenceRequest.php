<?php

declare(strict_types=1);

namespace App\Http\Requests\Officer;

use App\Models\Report;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SubmitEvidenceRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $report = $this->route('report');

        return $report instanceof Report
            ? Gate::forUser($this->user())->inspect('submitEvidence', $report)
            : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action_note' => ['required', 'string', 'min:20', 'max:1000'],
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
