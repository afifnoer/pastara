<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Services\ReportStateMachineService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ApproveSignOffRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $report = $this->route('report');
        if (! $report instanceof Report) {
            return false;
        }

        $actor = $this->user('sanctum');

        // Hukum 1 (Escrow Lock Law): internal roles MUST emit ESCROW_LOCK_VIOLATION rather than generic FORBIDDEN
        if ($actor !== null) {
            app(ReportStateMachineService::class)->guardUnilateralClose($actor, ReportStatus::VERIFIED_CLOSED);
        }

        $secretToken = is_string($this->input('secret_token')) ? $this->input('secret_token') : null;

        return Gate::forUser($actor)->inspect('signOff', [$report, $secretToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'secret_token' => ['nullable', 'string', 'max:32'],
        ];
    }
}
