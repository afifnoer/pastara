<?php

declare(strict_types=1);

namespace App\Http\Requests\Citizen;

use Illuminate\Foundation\Http\FormRequest;

class NearbyReportRequest extends FormRequest
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
        $bounds = config('pastara.cilacap');

        return [
            'category_id' => ['bail', 'required', 'integer', 'min:1', 'exists:categories,id'],
            'latitude' => ['required', 'numeric', "between:{$bounds['min_lat']},{$bounds['max_lat']}"],
            'longitude' => ['required', 'numeric', "between:{$bounds['min_lng']},{$bounds['max_lng']}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.between' => 'Lokasi berada di luar wilayah Kabupaten Cilacap.',
            'longitude.between' => 'Lokasi berada di luar wilayah Kabupaten Cilacap.',
            'category_id.exists' => 'Kategori laporan tidak ditemukan.',
        ];
    }
}
