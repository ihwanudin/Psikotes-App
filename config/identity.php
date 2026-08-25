<?php

return [
    'disk' => env('IDENTITY_FILESYSTEM_DISK', 'identity'),
    'max_upload_kilobytes' => 5_000,
    'min_dimension' => 480,
    'max_dimension' => 8_000,
    'upload_session_hours' => 2,
    'temporary_url_minutes' => 15,
];
