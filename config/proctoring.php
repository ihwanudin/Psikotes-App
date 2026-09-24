<?php

declare(strict_types=1);

return [
    'disk' => env('PROCTORING_FILESYSTEM_DISK', 'proctoring'),

    // SPEC.md §8A.2 / owner decision butir 14: 12-20s randomized cadence,
    // read from server config, never hardcoded client-side.
    'capture_min_interval_seconds' => 12,
    'capture_max_interval_seconds' => 20,

    // Owner decision butir 14: 480x360, JPEG quality ~0.6 (stored as an
    // integer 0-100 scale here since PHP's GD/Imagick quality APIs use it).
    'photo_max_width' => 480,
    'photo_max_height' => 360,
    'photo_jpeg_quality' => 60,
    'photo_max_upload_kilobytes' => 500,

    'signed_url_minutes' => 15,
];
