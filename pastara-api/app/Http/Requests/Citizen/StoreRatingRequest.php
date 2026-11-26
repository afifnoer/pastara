<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use App\Models\Report;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreRatingRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $report = $this->route('report');
        if (! $report instanceof Report) {
            return false;
        }

        $actor = $this->user('sanctum');
        $secretToken = is_string($this->input('secret_token')) ? $this->input('secret_token') : null;

        return Gate::forUser($actor)->inspect('rate', [$report, $secretToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'speed_score' => ['required', 'integer', 'between:1,5'],
            'physical_quality_score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
            'secret_token' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'speed_score.between' => 'Skor kecepatan penanganan harus antara 1 hingga 5.',
            'physical_quality_score.between' => 'Skor kualitas fisik perbaikan harus antara 1 hingga 5.',
        ];
    }
}
