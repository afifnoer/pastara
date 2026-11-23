<?php

declare(strict_types=1);

namespace App\Http\Requests\Unit;

use App\Enums\UserRole;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StartExecutionRequest extends FormRequest
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
        $unitId = (int) $this->user()?->opd_unit_id;
        $todayWib = CarbonImmutable::now('Asia/Jakarta')->toDateString();

        return [
            'execution_target_date' => ['bail', 'required', 'string', 'date_format:Y-m-d', "after:{$todayWib}"],
            'officer_id' => [
                'bail',
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::PETUGAS->value)
                    ->where('is_active', true)
                    ->where('opd_unit_id', $unitId),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'execution_target_date.date_format' => 'Format tanggal target eksekusi harus YYYY-MM-DD.',
            'execution_target_date.after' => 'Tanggal target eksekusi APBD harus lebih besar dari hari ini (WIB).',
            'officer_id.exists' => 'Petugas harus berstatus aktif dan berasal dari unit kerja yang sama.',
        ];
    }
}
