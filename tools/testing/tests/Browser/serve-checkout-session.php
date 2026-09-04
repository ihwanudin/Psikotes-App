<?php

declare(strict_types=1);

use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionMutation;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

// Disposable browser-only fixture. It refuses a workspace .env and all non-loopback clients.
$directory = getenv('ONCAM_CHECKOUT_BROWSER_DIRECTORY');
$port = getenv('ONCAM_CHECKOUT_BROWSER_PORT');
$temporaryRoot = realpath(sys_get_temp_dir());
if (! is_string($directory) || realpath(dirname($directory)) !== $temporaryRoot
    || ! preg_match('/^oncam-checkout-[a-f0-9]{32}$/D', basename($directory))
    || ! is_dir($directory) || file_exists($directory.'/.env')
    || ! is_string($port) || preg_match('/^8[0-9]{3}$/D', $port) !== 1) {
    throw new RuntimeException('A fresh checkout browser directory and bounded port are required.');
}

$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : 'serve';
if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'cli-server'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ! in_array($_SERVER['HTTP_HOST'] ?? '', ['psikotes.oncam.id', 'oncam.id'], true))) {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 4);
$database = $directory.'/browser.sqlite';
$initialize = $mode === 'init';
if ($initialize) {
    if (file_exists($database)) {
        throw new RuntimeException('Refusing to replace an existing checkout browser database.');
    }
    touch($database);
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'public'] as $path) {
        mkdir($directory.'/storage/'.$path, 0700, true);
    }
    file_put_contents($directory.'/fixtures.json', '{}');
} elseif (! file_exists($database)) {
    throw new RuntimeException('Initialize the disposable checkout browser fixture first.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_NAME' => 'ONCAM checkout browser synthetic',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'https://psikotes.oncam.id',
    'APP_CONFIG_CACHE' => $directory.'/config.php',
    'APP_ROUTES_CACHE' => $directory.'/routes.php',
    'APP_SERVICES_CACHE' => $directory.'/services.php',
    'APP_PACKAGES_CACHE' => $directory.'/packages.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'oncam_checkout_browser_login',
    'SESSION_SECURE_COOKIE' => 'true',
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

$app = require $root.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix($directory);
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory.'/storage');
$app->usePublicPath($directory.'/storage/public');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($database): void {
    $config = $app->make('config');
    if ($app->configurationIsCached() || $config->get('app.env') !== 'testing'
        || $config->get('database.default') !== 'sqlite'
        || $config->get('database.connections.sqlite.database') !== $database
        || ! in_array($config->get('database.connections.sqlite.url'), [null, ''], true)) {
        throw new RuntimeException('Unsafe checkout browser configuration; refusing to boot.');
    }
    $config->set('database.connections', ['sqlite' => $config->get('database.connections.sqlite')]);
    $config->set('database.redis', []);
    $config->set('assessment_integration.checkout_handoff.enabled', true);
    $config->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
    $config->set('assessment_integration.checkout_session', [
        'enabled' => true,
        'idle_minutes' => 30,
        'absolute_minutes' => 120,
        'terminal_retention_days' => 30,
        'http' => [
            'destination_origin' => 'https://psikotes.oncam.id',
            'trusted_exchange_origins' => [
                'https://seleksi.beasiswajepang.id',
                'https://seleksi.serbaindo.com',
            ],
            'exchange_per_minute' => 60,
            'hydrate_per_minute' => 120,
            'mutation_per_minute' => 60,
        ],
    ]);
});
$app->booting(function (): void {
    Http::preventStrayRequests();
});
$app->make(Kernel::class)->bootstrap();
URL::forceRootUrl('https://psikotes.oncam.id');
URL::forceScheme('https');

if ($initialize) {
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Disposable checkout browser migration failed.');
    }
    User::query()->create([
        'name' => 'Synthetic browser user',
        'email' => 'checkout-browser@example.test',
        'email_verified_at' => now(),
        'password' => 'browser-password',
    ]);
    echo "Disposable checkout browser fixture ready.\n";
    exit;
}

RateLimiter::for(CheckoutSessionHttpContract::LIMITER,
    fn (Request $request) => app(CheckoutSessionHttpContract::class)->rateLimit($request));
$boundary = [ProtectCheckoutSessionHttpBoundary::class, 'throttle:'.CheckoutSessionHttpContract::LIMITER];
Route::post('/checkout/session', [CheckoutSessionController::class, 'exchange'])
    ->middleware($boundary)->name('browser.checkout.exchange');
Route::get('/checkout', [CheckoutSessionController::class, 'show'])
    ->middleware([...$boundary, AuthenticateCheckoutSession::class])->name('browser.checkout.show');
Route::post('/checkout/logout', [CheckoutSessionController::class, 'logout'])
    ->middleware([...$boundary, AuthenticateCheckoutSession::class, VerifyCheckoutSessionMutation::class])
    ->name('browser.checkout.logout');
Route::get('/checkout/unavailable', [CheckoutSessionController::class, 'unavailable'])
    ->middleware($boundary)->name('browser.checkout.unavailable');
Route::get('/__browser/login', function (Request $request) {
    Auth::login(User::query()->where('email', 'checkout-browser@example.test')->sole());
    $request->session()->regenerate();

    return response('<a href="/__browser/auth">Continue</a>', 200, ['Cache-Control' => 'no-store, private'])
        ->header('Content-Type', 'text/html; charset=UTF-8');
})->middleware('web');
Route::get('/__browser/auth', function (Request $request) {
    $principal = $request->user()?->getAuthIdentifier();
    if ($principal === null) {
        return response('UNAUTH', 401, ['Cache-Control' => 'no-store, private']);
    }

    return response('AUTH:'.(string) $principal, 200, ['Cache-Control' => 'no-store, private']);
})->middleware(['web', 'auth']);
Route::getRoutes()->refreshNameLookups();
Route::getRoutes()->refreshActionLookups();

TrustProxies::at(['127.0.0.1']);
TrustProxies::withHeaders(SymfonyRequest::HEADER_X_FORWARDED_FOR
    | SymfonyRequest::HEADER_X_FORWARDED_HOST | SymfonyRequest::HEADER_X_FORWARDED_PORT
    | SymfonyRequest::HEADER_X_FORWARDED_PROTO);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/__browser/control/')) {
    if (($_SERVER['HTTP_X_BROWSER_HARNESS'] ?? '') !== 'synthetic-only') {
        http_response_code(404);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $alias = is_array($payload) && isset($payload['alias']) && is_string($payload['alias'])
        ? $payload['alias'] : '';
    if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $alias) !== 1) {
        response()->json(['error' => 'INVALID'], 422, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    $fixtures = json_decode((string) file_get_contents($directory.'/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($fixtures)) {
        throw new RuntimeException('Synthetic fixture registry is corrupt.');
    }

    if ($path === '/__browser/control/issue') {
        if (array_key_exists($alias, $fixtures)) {
            response()->json(['error' => 'DUPLICATE'], 409, ['Cache-Control' => 'no-store, private'])->send();
            exit;
        }
        $key = (string) Str::ulid();
        $sourceSystem = 'BROWSER_'.$key;
        $packageCode = 'B'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic browser person', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-browser-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert(['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'),
            fn () => app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail($client), $attemptPublicId, $sourceSystem,
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue,
            )));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            throw new RuntimeException('Synthetic handoff bearer unavailable.');
        }
        $fixtures[$alias] = compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem');
        file_put_contents($directory.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR), LOCK_EX);
        response()->json(['handoffToken' => $raw], 200, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }

    $fixture = $fixtures[$alias] ?? null;
    if (! is_array($fixture)) {
        response()->json(['error' => 'UNKNOWN'], 404, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($path === '/__browser/control/recover') {
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'),
            fn () => app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail((int) $fixture['client']),
                (string) $fixture['attemptPublicId'], (string) $fixture['sourceSystem'],
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery,
            )));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            throw new RuntimeException('Synthetic recovery bearer unavailable.');
        }
        response()->json(['handoffToken' => $raw], 200, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($path === '/__browser/control/revoke') {
        DB::table('integration_clients')->where('id', (int) $fixture['client'])->update(['enabled' => false]);
        response()->noContent(204, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($path === '/__browser/control/expire') {
        DB::table('checkout_handoffs')->where('assessment_participant_id', (int) $fixture['attempt'])->update([
            'issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-21 minutes')"),
            'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-20 minutes')"),
            'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-11 minutes')"),
        ]);
        DB::table('checkout_sessions')->where('assessment_participant_id', (int) $fixture['attempt'])->update([
            'established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-20 minutes')"),
            'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-10 minutes')"),
            'idle_expires_at' => DB::raw('CURRENT_TIMESTAMP'),
            'absolute_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+20 minutes')"),
        ]);
        response()->noContent(204, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($path === '/__browser/control/state') {
        response()->json([
            'sessions' => DB::table('checkout_sessions')->count(),
            'active' => DB::table('checkout_sessions')->where('status', 'ACTIVE')->count(),
            'terminal' => DB::table('checkout_sessions')->whereIn('status', ['REVOKED', 'EXPIRED'])->count(),
            'logoutAudits' => DB::table('audit_logs')->where('action', 'checkout_session.revoked')
                ->where('subject_id', (string) $fixture['attempt'])->where('context->reason', 'LOGOUT')->count(),
        ], 200, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    http_response_code(404);
    exit;
}

$app->handleRequest(Request::capture());
