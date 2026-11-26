<?php

declare(strict_types=1);

namespace App\Http\Requests\Officer;

use Illuminate\Foundation\Http\FormRequest;

class OfficerTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,history'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
