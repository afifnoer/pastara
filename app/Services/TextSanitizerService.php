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
        // 1. Sensor NIK 16 digit (rapat atau dipisah spasi/titik/tanda hubung per 4 digit)
        $sanitized = preg_replace(
            '/\b\d{4}[\s.\-]?\d{4}[\s.\-]?\d{4}[\s.\-]?\d{4}\b/',
            '[NIK DISENSOR]',
            $text
        ) ?? $text;

        // 2. Sensor Nomor HP Indonesia (08xx, +628xx, 628xx dengan panjang 10-14 digit)
        $sanitized = preg_replace(
            '/(?<!\d)(?:\+62|62|0)[\s\-]?8[1-9](?:[\s\-]?\d){7,11}\b/',
            '[NO. HP DISENSOR]',
            $sanitized
        ) ?? $sanitized;

        return trim($sanitized);
    }
}
