<?php

return [
    // Same local/S3-toggle shape as config('identity'): the disk actual
    // participant-facing IST asset bytes live on. See config/filesystems.php.
    'ist' => [
        'disk' => env('IST_ASSET_FILESYSTEM_DISK', 'ist-assets'),
    ],

    // Temporary-URL validity, capped further by the requesting session's own
    // remaining time -- see GetAssessmentSessionAssetUrl.
    'temporary_url_minutes' => 10,
];
