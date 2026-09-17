<?php

declare(strict_types=1);
use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

// Disposable browser fixture. Never boot the workspace .env or an active database.
$directory = getenv('ONCAM_P4B_DIRECTORY');
$temporaryRoot = realpath(sys_get_temp_dir());
if (! is_string($directory) || realpath(dirname($directory)) !== $temporaryRoot
    || ! preg_match('/^oncam-p4b-[a-f0-9]{32}$/', basename($directory)) || ! is_dir($directory)) {
    throw new RuntimeException('A dedicated oncam-p4b temporary directory is required.');
}
if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'cli-server'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8766')) {
    http_response_code(403);
    exit;
}
$root = dirname(__DIR__, 2);
$database = $directory.'/browser.sqlite';
$initialize = PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'init';
if ($initialize) {
    if (file_exists($database)) {
        throw new RuntimeException('Refusing to replace an existing preview database.');
    }
    touch($database);
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
        mkdir($directory.'/storage/'.$path, 0700, true);
    }
} elseif (! file_exists($database)) {
    throw new RuntimeException('Initialize the disposable fixture first.');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_NAME' => 'ONCAM - Uji lokal P4b',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8766',
    'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
    'APP_SERVICES_CACHE' => $directory.'/services.php', 'APP_PACKAGES_CACHE' => $directory.'/packages.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'oncam_p4b_test',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
    'XENDIT_SECRET_KEY' => '', 'XENDIT_CALLBACK_TOKEN' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix($directory);
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory.'/storage');
$app->booting(function () use ($app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
});

if ($initialize) {
    $app->make(Kernel::class)->bootstrap();
    Artisan::call('migrate', ['--force' => true]);
    $branch = Branch::create([
        'code' => 'PREVIEW', 'ref_code' => 'PREVIEW', 'name' => 'Lembaga Uji P4b',
        'organization_code' => 'PREVIEW', 'display_name' => 'Lembaga Uji P4b',
        'allowed_payer_types' => ['self'],
    ]);
    $client = IntegrationClient::create([
        'organization_id' => $branch->id, 'client_id' => 'uji-lokal-p4b',
        'credential_reference' => 'synthetic-only', 'rate_limit_policy' => ['requestsPerMinute' => 90],
    ]);
    IntegrationSource::create([
        'integration_client_id' => $client->id, 'source_system' => 'SUMBER_UJI_P4B',
        'allowed_assessment_packages' => ['WORK_V1'], 'allowed_funding_modes' => ['SPONSORED'],
        'allowed_payer_types' => null,
    ]);
    Admin::create([
        'name' => 'Admin Uji P4b', 'email' => 'p4b@example.test',
        'password' => 'p4b-test-password', 'role' => AdminRole::SuperAdmin,
    ]);
    echo "Disposable P4b fixture ready. Synthetic login: p4b@example.test / p4b-test-password\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)
    && is_file($asset) && in_array(strtolower(pathinfo($asset, PATHINFO_EXTENSION)), ['css', 'js', 'png', 'jpg', 'svg', 'ico', 'woff', 'woff2'], true)) {
    return false;
}
$app->handleRequest(Request::capture());
