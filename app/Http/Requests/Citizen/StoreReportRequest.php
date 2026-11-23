<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_secret')) {
            $raw = $this->input('is_secret');
            if (is_string($raw)) {
                $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $this->merge(['is_secret' => $parsed]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bounds = config('pastara.cilacap');
        $rawDistrict = $this->input('district_id');
        $parsedDistrict = is_scalar($rawDistrict) ? filter_var($rawDistrict, FILTER_VALIDATE_INT) : false;
        $districtId = ($parsedDistrict !== false && $parsedDistrict > 0) ? (int) $parsedDistrict : 0;

        return [
            'category_id' => ['bail', 'required', 'integer', 'min:1', Rule::exists('categories', 'id')->where('is_active', true)],
            'district_id' => ['bail', 'required', 'integer', 'min:1', 'exists:districts,id'],
            'village_id' => [
                'bail',
                'required',
                'integer',
                'min:1',
                Rule::exists('villages', 'id')->where(
                    static fn ($query) => $query->where('district_id', $districtId)
                ),
            ],
            'latitude' => ['required', 'numeric', "between:{$bounds['min_lat']},{$bounds['max_lat']}"],
            'longitude' => ['required', 'numeric', "between:{$bounds['min_lng']},{$bounds['max_lng']}"],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'min:10', 'max:120'],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'is_secret' => ['required', 'boolean'],
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }
}
