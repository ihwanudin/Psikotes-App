<?php

declare(strict_types=1);

use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AssessmentBillingFixture as Fixture;

// Local synthetic preview only. Never use a workspace .env or database.
$directory = getenv('ONCAM_BILL_PREVIEW_DIRECTORY');
if (! is_string($directory) || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
    || ! preg_match('/^oncam-bills-[a-f0-9]{32}$/D', basename($directory)) || ! is_dir($directory)
    || file_exists($directory.'/.env')) {
    throw new RuntimeException('A new dedicated oncam-bills temporary directory without .env is required.');
}
if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'cli-server'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8012')) {
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
    'APP_ENV' => 'testing', 'APP_NAME' => 'ONCAM - Tagihan sintetis',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8012',
    'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
    'APP_SERVICES_CACHE' => $directory.'/services.php', 'APP_PACKAGES_CACHE' => $directory.'/packages.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'oncam_bills_preview',
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
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($database): void {
    $config = $app->make('config');
    if ($app->configurationIsCached() || $config->get('app.env') !== 'testing'
        || $config->get('database.default') !== 'sqlite'
        || $config->get('database.connections.sqlite.database') !== $database
        || ! in_array($config->get('database.connections.sqlite.url'), [null, ''], true)) {
        throw new RuntimeException('Unsafe preview configuration; refusing to boot.');
    }
    $config->set('database.connections', ['sqlite' => $config->get('database.connections.sqlite')]);
    $config->set('database.redis', []);
    $config->set('filesystems.disks', ['local' => ['driver' => 'local', 'root' => $app->storagePath('app/private')]]);
});
$app->booting(function () use ($app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
    Mail::fake();
});

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'assets') {
    $app->make(Kernel::class)->bootstrap();
    exit(Artisan::call('filament:assets'));
}

if ($initialize) {
    $app->make(Kernel::class)->bootstrap();
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Disposable preview migration failed.');
    }
    $organization = null;
    $captureSnapshot = function (array $fixture): void {
        DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'Paket Uji Sintetis', 'is_active' => true]);
        DB::table('package_items')->insert(['package_id' => $fixture['package'], 'test_type' => 'ist', 'sort_order' => 1]);
        $snapshots = app(AssessmentPriceSnapshot::class);
        $snapshot = $snapshots->capture(TestPackage::with('items')->findOrFail($fixture['package']), false);
        AssessmentCharge::findOrFail($fixture['charge'])->update(['price_snapshot' => $snapshot]);
        $snapshots->fromCharge(AssessmentCharge::findOrFail($fixture['charge']), false);
    };
    $statuses = ['pending', 'paid', 'expired', 'rejected', 'unknown', 'reserved', 'issuing'];
    for ($i = 0; $i < 14; $i++) {
        $fixture = Fixture::create('organization', $organization === null ? null : ['organization' => $organization]);
        $organization = $fixture['organization'];
        $captureSnapshot($fixture);
        $status = $statuses[$i % count($statuses)];
        DB::table('assessment_bill_items')->insert([
            ...Fixture::item($fixture), 'settled_at' => $status === 'paid' ? '2026-08-31 13:00:00' : null,
        ]);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Peserta Sintetis '.($i + 1)]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update(['assessment_round_id' => 'Periode Uji Agustus']);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => $status, 'created_at' => '2026-08-31 12:00:00', 'expires_at' => '2026-09-01 12:00:00',
            'paid_at' => $status === 'paid' ? '2026-08-31 13:00:00' : null,
        ]);
    }
    $foreign = Fixture::create();
    $captureSnapshot($foreign);
    DB::table('assessment_bill_items')->insert(Fixture::item($foreign));
    Admin::create([
        'name' => 'Admin Cabang Sintetis', 'email' => 'bills@example.test',
        'password' => 'bills-preview-password', 'role' => AdminRole::BranchAdmin,
        'branch_id' => $organization, 'can_verify_payments' => true,
    ]);
    echo "Synthetic preview ready: bills@example.test / bills-preview-password\n";
    echo 'Foreign synthetic bill for denied URL check: '.$foreign['bill']."\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)
    && is_file($asset) && in_array(strtolower(pathinfo($asset, PATHINFO_EXTENSION)), ['css', 'js', 'png', 'jpg', 'svg', 'ico', 'woff', 'woff2'], true)) {
    return false;
}
$app->handleRequest(Request::capture());
