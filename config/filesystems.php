<?php

$identityDriver = env('IDENTITY_FILESYSTEM_DRIVER', 'local');
$paymentProofDriver = env('PAYMENT_PROOF_FILESYSTEM_DRIVER', 'local');
$reportDriver = env('REPORT_FILESYSTEM_DRIVER', 'local');
$istAssetDriver = env('IST_ASSET_FILESYSTEM_DRIVER', 'local');

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT', env('FILESYSTEM_S3_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'identity' => $identityDriver === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT', env('FILESYSTEM_S3_ENDPOINT')),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'root' => env('IDENTITY_FILESYSTEM_ROOT', 'identity'),
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/identity'),
                'serve' => true,
                'url' => '/private-identity-evidence',
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],

        'payment-proofs' => $paymentProofDriver === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT', env('FILESYSTEM_S3_ENDPOINT')),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'root' => env('PAYMENT_PROOF_FILESYSTEM_ROOT', 'payment-proofs'),
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/payment-proofs'),
                'serve' => true,
                'url' => '/private-payment-proofs',
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],

        'reports' => $reportDriver === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT', env('FILESYSTEM_S3_ENDPOINT')),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'root' => env('REPORT_FILESYSTEM_ROOT', 'reports'),
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/reports'),
                'serve' => true,
                'url' => '/private-reports',
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],

        // F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off): populated
        // only by `assets:sync-ist` from the checked-in
        // database/seeders/data/assets/ist/ source, never uploaded directly.
        'ist-assets' => $istAssetDriver === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT', env('FILESYSTEM_S3_ENDPOINT')),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'root' => env('IST_ASSET_FILESYSTEM_ROOT', 'ist-assets'),
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/ist-assets'),
                // 'serve' stays false: the framework's own signed-URL route
                // (Illuminate\Filesystem\ServeFile) has no Cache-Control on
                // its 403/404 responses, only on success, so a same-second
                // retry of a byte-identical signed URL can get a negative
                // response heuristically cached by the participant's
                // browser (RFC 9111). ServeIstAssetController + the
                // buildTemporaryUrlsUsing() override in AppServiceProvider
                // replace it: explicit no-store on every branch, plus a
                // signed nonce so no two issued URLs are ever identical.
                'serve' => false,
                'url' => '/private-ist-assets',
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
