<?php

declare(strict_types=1);

namespace App\Services;

class TextSanitizerService
{
    /**
     * Mask 16-digit Indonesian NIK and Indonesian mobile phone numbers.
     */
    public function sanitize(string $text): string
    {
        // 1a. Sensor NIK 16 digit rapat (termasuk menempel pada label huruf seperti NIK3301...)
        $sanitized = preg_replace(
            '/(?<!\d)\d{16}(?!\d)/',
            '[NIK DISENSOR]',
            $text
        ) ?? $text;

        // 1b. Sensor NIK 16 digit berpemisah (4-4-4-4 dengan titik/strip, 6-6-4 dengan strip/titik,
        // atau 4-4-4-4 dipisah spasi dengan awalan kode provinsi Indonesia valid agar deret tahun seperti "2023 2024 2025 2026" tidak ikut tersensor)
        $sanitized = preg_replace(
            '/(?<!\d)(?:\d{4}[.\-]\d{4}[.\-]\d{4}[.\-]\d{4}|\d{6}[.\-]\d{6}[.\-]\d{4}|(?:1[1-9]|21|3[1-6]|5[1-3]|6[1-5]|7[1-6]|8[12]|9[1-6])\d{2}[ \t]\d{4}[ \t]\d{4}[ \t]\d{4})(?!\d)/',
            '[NIK DISENSOR]',
            $sanitized
        ) ?? $sanitized;

        // 2. Sensor Nomor HP Indonesia (08xx, +628xx, 628xx panjang 10-14 digit, pemisah spasi/tab/titik/strip)
        $sanitized = preg_replace(
            '/(?<!\d)(?:\+62|62|0)[ \t.\-]?8[1-9](?:[ \t.\-]?\d){7,11}(?!\d)/',
            '[NO. HP DISENSOR]',
            $sanitized
        ) ?? $sanitized;

        return trim($sanitized);
    }
}
