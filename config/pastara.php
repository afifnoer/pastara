<?php

declare(strict_types=1);

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'cilacap' => [
        'center_lat' => (float) env('CILACAP_CENTER_LAT', -7.7188),
        'center_lng' => (float) env('CILACAP_CENTER_LNG', 109.0154),
        'min_lat' => (float) env('CILACAP_MIN_LAT', -7.8500),
        'max_lat' => (float) env('CILACAP_MAX_LAT', -7.1500),
        'min_lng' => (float) env('CILACAP_MIN_LNG', 108.5000),
        'max_lng' => (float) env('CILACAP_MAX_LNG', 109.4500),
    ],

    'signoff_window_hours' => (int) env('SIGNOFF_WINDOW_HOURS', 72),
    'signoff_reminder_hours' => (int) env('SIGNOFF_REMINDER_REMAINING_HOURS', 24),
    'apbd_doc_due_weekdays' => (int) env('APBD_DOC_DUE_WEEKDAYS', 7),
    'exif_max_age_hours' => (int) env('EXIF_MAX_AGE_HOURS', 24),

];
