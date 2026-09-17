<?php

declare(strict_types=1);

use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\FakePaymentProvider;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssessmentAccessFixture;

// Disposable, loopback-only browser fixture. It must never load a workspace .env or active database.
$directory = getenv('ONCAM_REVIEWER_BROWSER_DIRECTORY');
$temporaryRoot = realpath(sys_get_temp_dir());
if (! is_string($directory) || realpath(dirname($directory)) !== $temporaryRoot
    || ! preg_match('/^oncam-reviewer-[a-f0-9]{32}$/D', basename($directory))
    || ! is_dir($directory) || file_exists($directory.'/.env')) {
    throw new RuntimeException('A fresh oncam-reviewer temporary directory without .env is required.');
}

$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : 'serve';
$port = $mode === 'probe-production' ? '8024' : '8023';
if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'cli-server'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:'.$port)) {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 4);
$database = $directory.'/browser.sqlite';
$initialize = $mode === 'init';
if ($initialize) {
    if (file_exists($database)) {
        throw new RuntimeException('Refusing to replace an existing browser database.');
    }
    touch($database);
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private/payment-proofs', 'public'] as $path) {
        mkdir($directory.'/storage/'.$path, 0700, true);
    }
} elseif (! file_exists($database)) {
    throw new RuntimeException('Initialize the disposable browser fixture first.');
}

$environment = $mode === 'probe-production' ? 'browser-probe' : 'testing';
foreach ([
    'APP_ENV' => $environment,
    'APP_NAME' => 'ONCAM - Browser reviewer sintetis',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://127.0.0.1:'.$port,
    'APP_CONFIG_CACHE' => $directory.'/config-'.$environment.'.php',
    'APP_ROUTES_CACHE' => $directory.'/routes-'.$environment.'.php',
    'APP_SERVICES_CACHE' => $directory.'/services-'.$environment.'.php',
    'APP_PACKAGES_CACHE' => $directory.'/packages-'.$environment.'.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'oncam_reviewer_browser',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BCRYPT_ROUNDS' => '4',
    'XENDIT_SECRET_KEY' => '',
    'XENDIT_CALLBACK_TOKEN' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';

final class BrowserFixtureAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        return 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Crect width=%2240%22 height=%2240%22 fill=%22%2309090b%22/%3E%3C/svg%3E';
    }
}

$app = require $root.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix($directory);
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory.'/storage');
$app->usePublicPath($directory.'/storage/public');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($database, $environment): void {
    $config = $app->make('config');
    if ($app->configurationIsCached() || $config->get('app.env') !== $environment
        || $config->get('database.default') !== 'sqlite'
        || $config->get('database.connections.sqlite.database') !== $database
        || ! in_array($config->get('database.connections.sqlite.url'), [null, ''], true)) {
        throw new RuntimeException('Unsafe browser fixture configuration; refusing to boot.');
    }
    $config->set('database.connections', ['sqlite' => $config->get('database.connections.sqlite')]);
    $config->set('database.redis', []);
});
$app->booting(function () use ($app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
    Mail::fake();
    Storage::disk('payment-proofs')->buildTemporaryUrlsUsing(
        fn (): string => rtrim((string) config('app.url'), '/').'/__browser/proof',
    );
});
$app->booted(function (): void {
    Filament::getPanel('admin')->defaultAvatarProvider(BrowserFixtureAvatarProvider::class);
});

if ($initialize) {
    $app->make(Kernel::class)->bootstrap();
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Disposable browser migration failed.');
    }
    if (Artisan::call('filament:assets') !== 0) {
        throw new RuntimeException('Disposable Filament asset publication failed.');
    }

    $method = DB::table('payment_methods')->where('code', 'manual_transfer')->value('id');
    if (! is_int($method)) {
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'manual_transfer', 'display_name' => 'Transfer Manual', 'is_active' => false,
        ]);
    }
    $fixtures = [];
    $organization = null;
    foreach (['approve', 'reject', 'stale'] as $index => $alias) {
        $fixture = AssessmentAccessFixture::create(identity: $organization === null ? null : ['organization' => $organization]);
        $organization = $fixture['organization'];
        DB::table('branches')->where('id', $organization)->update(['name' => 'Organisasi Sintetis']);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Nama Privat Tidak Boleh Tampil']);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'external_candidate_id' => 'KANDIDAT-PRIVAT-'.$index,
            'assessment_status' => 'PROVISIONED',
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
            ->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        $package = TestPackage::query()->with('items')->whereKey($fixture['package'])->first();
        $charge = AssessmentCharge::query()->whereKey($fixture['charge'])->first();
        if (! $package instanceof TestPackage || ! $charge instanceof AssessmentCharge) {
            throw new RuntimeException('Synthetic billing graph is incomplete.');
        }
        $snapshot = app(AssessmentPriceSnapshot::class)->capture($package, false);
        $charge->update(['price_snapshot' => $snapshot]);
        app(AssessmentPriceSnapshot::class)->fromCharge($charge->refresh(), false);

        $contents = "%PDF-1.4\nsynthetic browser proof\n%%EOF";
        $key = 'assessment-bills/'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'/'.str_repeat(chr(102 - $index), 62).'.pdf';
        Storage::disk('payment-proofs')->put($key, $contents, ['visibility' => 'private']);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'payment_method_id' => $method,
            'status' => 'pending', 'paid_at' => null, 'gateway_ref' => null, 'invoice_url' => null,
            'proof_object_key' => $key, 'proof_checksum_sha256' => hash('sha256', $contents),
            'proof_mime_type' => 'application/pdf', 'proof_size_bytes' => strlen($contents),
            'proof_uploaded_at' => now()->subMinutes(3 - $index)->utc()->startOfSecond(),
            'verified_at' => null, 'verified_by_admin_id' => null, 'rejection_reason' => null,
        ]);
        $fixtures[$alias] = $fixture['bill'];
    }

    foreach ([
        ['reviewer@example.test', AdminRole::SuperAdmin, null, false],
        ['branch@example.test', AdminRole::BranchAdmin, $organization, true],
        ['staff@example.test', AdminRole::Staff, $organization, true],
        ['psychologist@example.test', AdminRole::Psychologist, null, false],
    ] as [$email, $role, $branchId, $legacy]) {
        Admin::create([
            'name' => 'Akun Sintetis', 'email' => $email, 'password' => 'browser-password',
            'role' => $role, 'branch_id' => $branchId, 'can_verify_payments' => $legacy,
        ]);
    }
    file_put_contents($directory.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo "Disposable reviewer browser fixture ready.\n";
    exit;
}

$app->make(Kernel::class)->bootstrap();
if ($mode === 'probe-production') {
    foreach ($app->make('router')->getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'assessment-bill-reviews')) {
            throw new RuntimeException('Reviewer resource was discovered outside testing.');
        }
    }
    echo "Production discovery probe passed.\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/__browser/proof') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="id"><title>Bukti sintetis</title><body><main><h1>Bukti pembayaran sintetis</h1><p>Konten uji lokal.</p></main></body></html>';
    exit;
}

if (str_starts_with((string) $path, '/__browser/control/')) {
    if (($_SERVER['HTTP_X_BROWSER_HARNESS'] ?? '') !== 'synthetic-only') {
        http_response_code(404);
        exit;
    }
    $fixtures = json_decode((string) file_get_contents($directory.'/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($path === '/__browser/control/replace') {
        DB::table('assessment_bills')->where('id', $fixtures['stale'])->update([
            'proof_object_key' => 'assessment-bills/replaced/'.str_repeat('a', 62).'.pdf',
            'proof_checksum_sha256' => str_repeat('a', 64),
            'proof_uploaded_at' => now()->utc()->startOfSecond(),
        ]);
        response()->noContent()->send();
        exit;
    }
    if ($path === '/__browser/control/state') {
        $state = [];
        foreach ($fixtures as $alias => $id) {
            $bill = AssessmentBill::query()->whereKey((int) $id)->first();
            if (! $bill instanceof AssessmentBill) {
                throw new RuntimeException('Synthetic review bill is missing.');
            }
            $state[$alias] = [
                'status' => $bill->status,
                'decisionAudits' => DB::table('audit_logs')->where('subject_type', AssessmentBill::class)
                    ->where('subject_id', (string) $id)
                    ->whereIn('action', ['assessment_bill.paid', 'assessment_bill.rejected'])->count(),
                'accessAudits' => DB::table('audit_logs')->where('subject_type', AssessmentBill::class)
                    ->where('subject_id', (string) $id)
                    ->where('action', 'assessment_bill.proof_temporary_url_issued')->count(),
            ];
        }
        $state['activationOutbox'] = DB::table('outbox_messages')->where('topic', 'assessment.activation')->count();
        response()->json($state, 200, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    http_response_code(404);
    exit;
}

$temporaryPublic = realpath($directory.'/storage/public');
$asset = realpath($directory.'/storage/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($temporaryPublic !== false && $asset !== false
    && str_starts_with($asset, $temporaryPublic.DIRECTORY_SEPARATOR) && is_file($asset)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'woff2' => 'font/woff2',
        'woff' => 'font/woff', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon'];
    $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    if (! isset($types[$extension])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: '.$types[$extension]);
    header('Cache-Control: no-store');
    readfile($asset);
    exit;
}
$rootPublic = realpath($root.'/public');
$rootAsset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($rootPublic !== false && $rootAsset !== false && str_starts_with($rootAsset, $rootPublic.DIRECTORY_SEPARATOR)
    && is_file($rootAsset) && in_array(strtolower(pathinfo($rootAsset, PATHINFO_EXTENSION)), ['png', 'svg', 'ico'], true)) {
    readfile($rootAsset);
    exit;
}
$app->handleRequest(Request::capture());
