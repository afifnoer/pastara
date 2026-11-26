<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use App\Models\Report;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RejectSignOffRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $report = $this->route('report');
        if (! $report instanceof Report) {
            return false;
        }

        $actor = $this->user('sanctum');
        $secretToken = is_string($this->input('secret_token')) ? $this->input('secret_token') : null;

        return Gate::forUser($actor)->inspect('signOff', [$report, $secretToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            'secret_token' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penolakan (veto) wajib diisi.',
            'reason.min' => 'Alasan penolakan minimal 20 karakter.',
            'photos.required' => 'Minimal 1 foto bukti kondisi lapangan wajib diunggah untuk mengajukan veto.',
            'photos.min' => 'Minimal 1 foto bukti kondisi lapangan wajib diunggah untuk mengajukan veto.',
            'photos.max' => 'Maksimal 3 foto bukti yang dapat diunggah.',
            'photos.*.max' => 'Ukuran setiap foto maksimal 5 MB.',
        ];
    }
}
