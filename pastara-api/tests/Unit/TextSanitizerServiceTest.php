<?php

declare(strict_types=1);

use App\Services\TextSanitizerService;

it('censors 16-digit NIK and Indonesian phone numbers across formats (TC-F-07)', function (): void {
    $sanitizer = new TextSanitizerService;

    $raw = implode("\n", [
        'NIK saya 3301123456789012 dan NIK istri 3301 1234 5678 9099.',
        'Format titik 3301.1234.5678.0001 dan strip 3301-1234-5678-0002.',
        'Hubungi 081234567890 atau +6281234567890 atau 6281345678901.',
        'Atau WA 0812-3456-7890 dan +62 815-7890-1234 serta 0856 1234 5678.',
    ]);

    $clean = $sanitizer->sanitize($raw);

    expect($clean)
        ->not->toContain('3301123456789012')
        ->not->toContain('3301 1234 5678 9099')
        ->not->toContain('3301.1234.5678.0001')
        ->not->toContain('3301-1234-5678-0002')
        ->not->toContain('081234567890')
        ->not->toContain('+6281234567890')
        ->not->toContain('6281345678901')
        ->not->toContain('0812-3456-7890')
        ->not->toContain('+62 815-7890-1234')
        ->not->toContain('0856 1234 5678')
        ->and(substr_count($clean, '[NIK DISENSOR]'))->toBe(4)
        ->and(substr_count($clean, '[NO. HP DISENSOR]'))->toBe(6);
});

it('preserves regular numbers such as RT/RW, years, and ticket codes', function (): void {
    $sanitizer = new TextSanitizerService;

    $raw = '  Jalan rusak di RT 03 RW 08 sejak tahun 2026, cek tiket PST-12345678 ya.  ';
    $clean = $sanitizer->sanitize($raw);

    expect($clean)->toBe('Jalan rusak di RT 03 RW 08 sejak tahun 2026, cek tiket PST-12345678 ya.');
});
