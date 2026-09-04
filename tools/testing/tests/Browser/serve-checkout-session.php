<?php

declare(strict_types=1);

use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionMutation;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\Support\AssessmentAccessFixture;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    // Control/bootstrap exceptions may contain SQL/credentials; never print their details.
    http_response_code(500);
    echo "Checkout browser harness refused.\n";
    exit(1);
});

// Pure checks only: no autoloader, application, filesystem writes or database.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--self-test') {
    $checks = [
        checkoutBrowserSafeRelative('app/Http/Controllers/CheckoutSessionController.php'),
        ! checkoutBrowserSafeRelative('../.env'),
        ! checkoutBrowserSafeRelative('app/../../.env'),
        ! checkoutBrowserSafeRelative('C:/active/app.php'),
        ! checkoutBrowserSafeRelative('app\\secret.php'),
        checkoutBrowserSnapshotMatches(['participants' => ['a']], ['participants' => ['a']]),
        ! checkoutBrowserSnapshotMatches(['participants' => ['a']], ['participants' => ['b']]),
        ! checkoutBrowserSnapshotMatches(['participants' => ['a']], []),
        ! checkoutBrowserSnapshotMatches([], ['participants' => []]),
        checkoutBrowserControlAllowed(['HTTP_X_BROWSER_HARNESS' => 'synthetic-only', 'REQUEST_METHOD' => 'POST']),
        ! checkoutBrowserControlAllowed(['HTTP_X_BROWSER_HARNESS' => 'synthetic-only', 'REQUEST_METHOD' => 'GET']),
        ! checkoutBrowserControlAllowed(['REQUEST_METHOD' => 'POST']),
        ! checkoutBrowserControlAllowed(['HTTP_X_BROWSER_HARNESS' => 'synthetic-only', 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'null']),
        ! checkoutBrowserControlAllowed(['HTTP_X_BROWSER_HARNESS' => 'synthetic-only', 'REQUEST_METHOD' => 'POST', 'HTTP_X_FORWARDED_PROTO' => 'https']),
        ! checkoutBrowserControlAllowed(['HTTP_X_BROWSER_HARNESS' => 'synthetic-only', 'REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_MODE' => 'navigate']),
        ! checkoutBrowserControlAllowed(['HTTP_X_BROWSER_HARNESS' => 'synthetic-only', 'REQUEST_METHOD' => 'POST', 'HTTP_COOKIE' => 'synthetic']),
    ];
    foreach ([
        'aa_ER@saaho.php', 'be_BY@latin.php', 'ks_IN@devanagari.php', 'nan_TW@latin.php',
        'sd_IN@devanagari.php', 'sr_RS@latin.php', 'tt_RU@iqtelif.php', 'uz_UZ@cyrillic.php',
    ] as $locale) {
        $checks[] = checkoutBrowserSafeRelative('vendor/nesbot/carbon/src/Carbon/Lang/'.$locale);
    }
    foreach ([
        '', '.', '..', '/vendor/locale@latin.php', '//host/locale@latin.php',
        '../locale@latin.php', 'vendor@local/../secret.php', 'vendor@local/./locale.php',
        'vendor/../@locale.php', 'vendor/./@locale.php', 'vendor@local//locale.php',
        'vendor@local/', 'C:/locale@latin.php', 'C:@locale.php', '@C:/locale.php',
        'vendor@local/C:/locale.php', 'vendor@local\\..\\secret.php',
        '\\host\\locale@latin.php', 'vendor/locale@latin.php:stream',
        'vendor/%2e%2e/@locale.php', "vendor/locale@latin.php\n", "vendor/locale@latin.php\0",
    ] as $unsafePath) {
        $checks[] = ! checkoutBrowserSafeRelative($unsafePath);
    }
    if (in_array(false, $checks, true)) {
        fwrite(STDERR, "Harness pure checks failed.\n");
        exit(1);
    }
    echo json_encode(['pureChecks' => count($checks), 'passed' => true], JSON_THROW_ON_ERROR)."\n";
    exit;
}

// Disposable browser-only fixture. It refuses a workspace .env and all non-loopback clients.
$directory = getenv('ONCAM_CHECKOUT_BROWSER_DIRECTORY');
$port = getenv('ONCAM_CHECKOUT_BROWSER_PORT');
$temporaryRoot = realpath(sys_get_temp_dir());
if (! is_string($directory) || realpath(dirname($directory)) !== $temporaryRoot
    || ! preg_match('/^oncam-checkout-[a-f0-9]{32}$/D', basename($directory))
    || ! is_dir($directory) || file_exists($directory.'/.env')
    || $port !== '8126') {
    throw new RuntimeException('A fresh checkout browser directory and bounded port are required.');
}

$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : 'serve';
if (! in_array($mode, ['init', 'verify', 'serve'], true)
    || (PHP_SAPI === 'cli' && $mode === 'serve') || PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    throw new RuntimeException('Unsupported harness mode/runtime.');
}
foreach (['pdo_sqlite', 'mbstring', 'openssl', 'dom', 'fileinfo'] as $extension) {
    if (! extension_loaded($extension)) {
        throw new RuntimeException('Required harness extension missing.');
    }
}
if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'cli-server'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') !== $port
    || ! in_array($_SERVER['HTTP_HOST'] ?? '', ['psikotes.oncam.id', 'oncam.id'], true))) {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 4);
if (realpath($root) !== realpath($directory.'/source') || ! is_dir($directory.'/source')
    || glob($directory.'/.env*') !== [] || glob($root.'/.env*') !== []
    || file_exists($directory.'/config.php') || file_exists($directory.'/routes.php')
    || array_diff(glob($root.'/bootstrap/cache/*') ?: [], [$root.'/bootstrap/cache/.gitignore']) !== []) {
    throw new RuntimeException('Fresh env-free source copy required.');
}
checkoutBrowserAssertTree($directory);
$manifest = json_decode((string) file_get_contents($directory.'/source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$manifestDigest = getenv('ONCAM_CHECKOUT_BROWSER_MANIFEST_SHA256');
if (! is_string($manifestDigest) || preg_match('/^[a-f0-9]{64}$/D', $manifestDigest) !== 1
    || ! hash_equals($manifestDigest, hash_file('sha256', $directory.'/source-manifest.json'))) {
    throw new RuntimeException('Reviewed manifest digest required.');
}
if (! is_array($manifest) || count($manifest) < 10 || count($manifest) > 50000) {
    throw new RuntimeException('Source manifest required.');
}
foreach ($manifest as $relative => $digest) {
    if (! is_string($relative) || ! checkoutBrowserSafeRelative($relative)
        || ! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)
        || ! is_file($root.'/'.$relative) || ! hash_equals($digest, hash_file('sha256', $root.'/'.$relative))) {
        throw new RuntimeException('Source manifest mismatch.');
    }
}
$inventory = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $inventory[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
sort($inventory, SORT_STRING);
$manifestFiles = array_keys($manifest);
sort($manifestFiles, SORT_STRING);
if ($inventory !== $manifestFiles) {
    throw new RuntimeException('Unmanifested source file.');
}
foreach (['composer.lock', 'vendor/autoload.php', 'app/Http/Controllers/CheckoutSessionController.php',
    'resources/views/checkout/summary.blade.php', 'app/Actions/Integrations/CheckoutSessionLifecycle.php',
    'app/Services/Integrations/CheckoutSummaryComposer.php', 'app/Data/Integrations/CheckoutSummary.php',
    'tools/testing/tests/Browser/serve-checkout-session.php', 'tools/testing/tests/Browser/checkout-session.browser.mjs',
    'tests/Support/AssessmentAccessFixture.php', 'tests/Support/AssessmentBillingFixture.php'] as $required) {
    if (! array_key_exists($required, $manifest)) {
        throw new RuntimeException('Incomplete source manifest.');
    }
}
$database = $directory.'/browser.sqlite';
$initialize = $mode === 'init';
if ($initialize) {
    if (file_exists($database) || file_exists($directory.'/baseline.json') || file_exists($directory.'/fixtures.json')) {
        throw new RuntimeException('Refusing to replace an existing checkout browser database.');
    }
    touch($database);
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'public'] as $path) {
        mkdir($directory.'/storage/'.$path, 0700, true);
    }
} elseif (! file_exists($database)) {
    throw new RuntimeException('Initialize the disposable checkout browser fixture first.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_NAME' => 'ONCAM checkout browser synthetic',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_DEBUG' => 'false',
    'LOG_CHANNEL' => 'null',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
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
    $config->set('consent.documents.dass.text', 'Synthetic DASS </script><img src=x onerror="alert(1)"> & 日本語');
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
$app->booting(function () use ($app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
    Mail::fake();
});
$app->make(Kernel::class)->bootstrap();
// Laravel installs its own global handler during bootstrap; control/CLI errors must stay opaque too.
set_exception_handler(static function (Throwable $error) use ($directory): void {
    file_put_contents($directory.'/violations.txt', "HARNESS_EXCEPTION\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo "Checkout browser harness refused.\n";
    exit(1);
});
app(ExceptionHandler::class)->reportable(static function (Throwable $error) use ($directory): bool {
    file_put_contents($directory.'/violations.txt', "FRAMEWORK_EXCEPTION\n", FILE_APPEND | LOCK_EX);

    return false;
});
if (! app(PaymentProvider::class) instanceof FakePaymentProvider || ! app(Notifier::class) instanceof FakeNotifier) {
    throw new RuntimeException('Fake bindings required.');
}
// No provider/notifier resolution is allowed by any measured request or fixture.
foreach ([PaymentProvider::class, Notifier::class] as $contract) {
    $app->beforeResolving($contract, static function () use ($directory): never {
        file_put_contents($directory.'/violations.txt', "PROVIDER_RESOLUTION\n", FILE_APPEND | LOCK_EX);
        throw new RuntimeException('Unexpected provider resolution.');
    });
}
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
    $fixtures = checkoutBrowserSeed();
    checkoutBrowserWriteNew($directory.'/fixtures.json', $fixtures);
    checkoutBrowserWriteNew($directory.'/baseline.json', checkoutBrowserRows());
    echo "Disposable checkout browser fixture ready.\n";
    exit;
}

$fixtures = json_decode((string) file_get_contents($directory.'/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$baseline = json_decode((string) file_get_contents($directory.'/baseline.json'), true, 512, JSON_THROW_ON_ERROR);
if (! is_array($fixtures) || ! is_array($baseline)) {
    throw new RuntimeException('Missing fixed baseline.');
}
if ($mode === 'verify') {
    if (file_exists($directory.'/violations.txt')) {
        throw new RuntimeException('Recorded harness violation.');
    }
    checkoutBrowserVerify($baseline, $fixtures);
    echo "{\"businessMatchesFixedPlan\":true,\"lifecycleAuditsChecked\":true}\n";
    exit;
}

RateLimiter::for(CheckoutSessionHttpContract::LIMITER,
    fn (Request $request) => app(CheckoutSessionHttpContract::class)->rateLimit($request));
$boundary = [ProtectCheckoutSessionHttpBoundary::class, 'throttle:'.CheckoutSessionHttpContract::LIMITER];
Route::post('/checkout/session', [CheckoutSessionController::class, 'exchange'])
    ->middleware($boundary)->name('browser.checkout.exchange');
Route::get('/checkout', [CheckoutSessionController::class, 'summary'])
    ->middleware($boundary)->name('browser.checkout.summary');
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
    // Driver API is direct HTTP loopback; browser TLS always carries proxy headers.
    if (! checkoutBrowserControlAllowed($_SERVER)) {
        http_response_code(404);
        exit;
    }
    $rawBody = (string) file_get_contents('php://input', length: 257);
    $payload = strlen($rawBody) <= 256 ? json_decode($rawBody, true) : null;
    $alias = is_array($payload) && isset($payload['alias']) && is_string($payload['alias'])
        ? $payload['alias'] : '';
    if (! is_array($payload) || array_keys($payload) !== ['alias'] || ! array_key_exists($alias, $fixtures)) {
        response()->json(['error' => 'INVALID'], 422, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    $fixtures = json_decode((string) file_get_contents($directory.'/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($fixtures)) {
        throw new RuntimeException('Synthetic fixture registry is corrupt.');
    }

    $fixture = $fixtures[$alias] ?? null;
    if (! is_array($fixture)) {
        response()->json(['error' => 'UNKNOWN'], 404, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if (in_array($path, ['/__browser/control/issue', '/__browser/control/recover'], true)) {
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'),
            fn () => app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail((int) $fixture['client']),
                (string) $fixture['attemptPublicId'], (string) $fixture['sourceSystem'],
                'ih1_'.bin2hex(random_bytes(16)), $path === '/__browser/control/issue' ? CheckoutHandoffIntent::Issue : CheckoutHandoffIntent::Recovery,
            )));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            throw new RuntimeException('Synthetic recovery bearer unavailable.');
        }
        response()->json(['handoffToken' => $raw], 200, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    // Fixed fixture-only prerequisite transition; never a participant mutation endpoint.
    if ($path === '/__browser/control/prepare-price' && $alias === 'price') {
        if (DB::table('checkout_sessions')->where('assessment_participant_id', $fixture['attempt'])->where('status', 'ACTIVE')->count() !== 1
            || DB::table('assessment_participants')->where('id', $fixture['attempt'])->where('assessment_status', 'PROVISIONED')
                ->update(['assessment_status' => 'READY']) !== 1) {
            throw new RuntimeException('Price fixture transition refused.');
        }
        response()->noContent(204, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($path === '/__browser/control/revoke' && $alias === 'revoke') {
        DB::table('integration_clients')->where('id', (int) $fixture['client'])->update(['enabled' => false]);
        response()->noContent(204, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($path === '/__browser/control/expire' && $alias === 'expiry') {
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
    if ($path === '/__browser/control/verify') {
        if (file_exists($directory.'/violations.txt')) {
            throw new RuntimeException('Recorded harness violation.');
        }
        checkoutBrowserVerify($baseline, $fixtures);
        response()->json(['businessMatchesFixedPlan' => true], 200, ['Cache-Control' => 'no-store, private'])->send();
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

$requestBaseline = checkoutBrowserRows();
$clockReads = 0;
$idleWrites = 0;
DB::listen(static function (QueryExecuted $query) use (&$clockReads, &$idleWrites): void {
    $clockReads += str_contains($query->sql, 'AS current_time') ? 1 : 0;
    $idleWrites += str_starts_with($query->sql, 'update "checkout_sessions"') ? 1 : 0;
});
$app->handleRequest(Request::capture());
$requestAfter = checkoutBrowserRows();
foreach (['checkout_sessions', 'checkout_handoffs', 'audit_logs'] as $lifecycle) {
    unset($requestBaseline[$lifecycle], $requestAfter[$lifecycle]);
}
if (! checkoutBrowserSnapshotMatches($requestBaseline, $requestAfter)
    || ($path === '/checkout' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && http_response_code() === 200
        && ($clockReads !== 1 || $idleWrites !== 1))) {
    file_put_contents($directory.'/violations.txt', "REQUEST_POSTCONDITION\n", FILE_APPEND | LOCK_EX);
    throw new RuntimeException('Request postcondition mismatch.');
}

if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
    file_put_contents($directory.'/violations.txt', "CONTEXT_LEAK\n", FILE_APPEND | LOCK_EX);
    throw new RuntimeException('Request context was not restored.');
}
Http::assertNothingSent();
Mail::assertNothingSent();

function checkoutBrowserSafeRelative(string $path): bool
{
    return preg_match('#^[a-zA-Z0-9_@.-]+(?:/[a-zA-Z0-9_@.-]+)*$#D', $path) === 1
        && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true);
}

function checkoutBrowserControlAllowed(array $server): bool
{
    return ($server['HTTP_X_BROWSER_HARNESS'] ?? '') === 'synthetic-only'
        && ($server['REQUEST_METHOD'] ?? '') === 'POST'
        && ! array_key_exists('HTTP_X_FORWARDED_PROTO', $server) && ! array_key_exists('HTTP_ORIGIN', $server)
        && ! array_key_exists('HTTP_SEC_FETCH_MODE', $server) && ! array_key_exists('HTTP_COOKIE', $server);
}

function checkoutBrowserSnapshotMatches(array $before, array $after): bool
{
    return $before !== [] && $before === $after;
}

function checkoutBrowserAssertTree(string $path): void
{
    $real = realpath($path);
    if ($real === false || is_link($path) || strcasecmp(str_replace('\\', '/', $real), str_replace('\\', '/', $path)) !== 0) {
        throw new RuntimeException('Noncanonical/reparse path refused.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $resolved = $entry->getRealPath();
        if ($entry->isLink() || $resolved === false
            || strcasecmp(str_replace('\\', '/', $resolved), str_replace('\\', '/', $entry->getPathname())) !== 0) {
            throw new RuntimeException('Linked/noncanonical scratch entry refused.');
        }
    }
}

function checkoutBrowserWriteNew(string $path, array $data): void
{
    $handle = fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('Refusing baseline replacement.');
    }
    try {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Incomplete fixture baseline.');
        }
    } finally {
        fclose($handle);
    }
}

/** Captures EVERY real SQLite table (including engine/clinical tables), not a best-effort subset. */
function checkoutBrowserRows(): array
{
    $names = array_map(static fn ($row) => $row->name,
        DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
    foreach (['participants', 'assessment_participants', 'assessment_bills', 'assessment_bill_items',
        'assessment_charges', 'assessment_entitlements', 'consent_records', 'identity_evidence',
        'identity_verifications', 'assessment_invitations', 'orders', 'outbox_messages',
        'checkout_sessions', 'checkout_handoffs', 'integration_clients', 'audit_logs'] as $required) {
        if (! in_array($required, $names, true)) {
            throw new RuntimeException('Required table missing.');
        }
    }
    $snapshot = [];
    foreach ($names as $table) {
        $rows = DB::table($table)->get()->map(static function ($row): string {
            $values = (array) $row;
            ksort($values);

            return json_encode($values, JSON_THROW_ON_ERROR);
        })->all();
        sort($rows, SORT_STRING);
        $snapshot[$table] = $rows;
    }

    return $snapshot;
}

/** Fixed cohorts are all created before the immutable measurement baseline. */
function checkoutBrowserSeed(): array
{
    $fixtures = [];
    foreach (['first', 'second', 'expiry', 'revoke', 'orphan', 'foreign-origin', 'wrong-host', 'no-js', 'price', 'peer', 'same-person'] as $alias) {
        $identity = match ($alias) {
            'peer' => ['organization' => $fixtures['price']['organization']],
            'same-person' => ['organization' => $fixtures['first']['organization'], 'participant' => $fixtures['first']['participant']],
            default => null,
        };
        $row = AssessmentAccessFixture::create(identity: $identity);
        $attempt = AssessmentParticipant::findOrFail($row['attempt']);
        DB::table('branches')->where('id', $row['organization'])->update(['status' => 'ACTIVE', 'is_active' => true]);
        DB::table('integration_clients')->where('id', $attempt->integration_client_id)->update(['enabled' => true]);
        DB::table('participants')->where('id', $row['participant'])->update(['full_name' => $alias === 'same-person' ? 'Person first' : 'Person '.$alias]);
        $source = DB::table('integration_sources')->insertGetId(['integration_client_id' => $attempt->integration_client_id,
            'source_system' => $attempt->source_system, 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => json_encode([DB::table('packages')->where('id', $row['package'])->value('code')], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]']);
        DB::table('packages')->where('id', $row['package'])->update(['name' => 'Package '.$alias]);
        $paid = in_array($alias, ['price', 'peer'], true);
        DB::table('assessment_participants')->where('id', $row['attempt'])->update([
            'assessment_status' => $alias === 'peer' ? 'READY' : 'PROVISIONED', 'funding_mode' => $paid ? 'INVOICED_TO_ORGANIZATION' : null,
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
        if (! $paid) {
            DB::table('assessment_entitlements')->where('charge_id', $row['charge'])->delete();
            DB::table('assessment_bill_items')->where('charge_id', $row['charge'])->delete();
            DB::table('assessment_charges')->where('id', $row['charge'])->delete();
            DB::table('assessment_bills')->where('id', $row['bill'])->delete();
            DB::table('participants')->where('id', $row['participant'])->update(['birth_date' => null, 'gender' => null,
                'education_level' => null, 'intended_field' => null, 'email' => null]);
        }
        $fixtures[$alias] = [...$row, 'client' => $attempt->integration_client_id, 'source' => $source,
            'attemptPublicId' => $attempt->assessment_attempt_id, 'sourceSystem' => $attempt->source_system];
    }
    $price = $fixtures['price'];
    $peer = $fixtures['peer'];
    DB::table('assessment_bill_items')->where('id', $peer['item'])->update(['bill_id' => $price['bill']]);
    DB::table('assessment_bills')->where('id', $peer['bill'])->delete();
    DB::table('assessment_bills')->where('id', $price['bill'])->update(['amount' => 200, 'item_count' => 2,
        'gateway_ref' => 'PRIVATE_GATEWAY', 'invoice_url' => 'https://synthetic.invalid/PRIVATE_INVOICE']);
    $charge = AssessmentCharge::findOrFail($price['charge']);
    $snapshot = $charge->price_snapshot;
    $snapshot['testTypes'] = ['dass21', 'ist'];
    $charge->update(['price_snapshot' => $snapshot]);
    DB::table('assessment_entitlements')->insert(['assessment_participant_id' => $price['attempt'],
        'organization_id' => $price['organization'], 'participant_id' => $price['participant'],
        'charge_id' => $price['charge'], 'test_type' => 'dass21', 'status' => 'ready', 'ready_at' => now()]);
    DB::table('consent_records')->where('participant_id', $price['participant'])->where('consent_type', 'dass')->update(['status' => 'declined']);
    DB::table('packages')->where('id', $price['package'])->update(['name' => 'PRIVATE_CHANGED_CATALOG', 'amount' => 999]);
    DB::table('participants')->where('id', $peer['participant'])->update(['full_name' => 'PRIVATE_OTHER_PROFILE']);
    DB::table('participants')->where('id', $price['participant'])->update(['full_name' => 'Person price </script><img src=x onerror="alert(1)"> & 日本語']);

    return $fixtures;
}

function checkoutBrowserVerify(array $baseline, array $fixtures): void
{
    $actual = checkoutBrowserRows();
    if (array_keys($baseline) !== array_keys($actual)) {
        throw new RuntimeException('Measured table set changed.');
    }
    foreach ($baseline['integration_clients'] as &$serialized) {
        $row = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        if ($row['id'] === $fixtures['revoke']['client']) {
            $row['enabled'] = 0; // Only the fixed revoke control is allowed to change this one cell.
            $serialized = json_encode($row, JSON_THROW_ON_ERROR);
        }
    }
    unset($serialized);
    sort($baseline['integration_clients'], SORT_STRING);
    foreach ($baseline['assessment_participants'] as &$serialized) {
        $row = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        if ($row['id'] === $fixtures['price']['attempt']) {
            $row['assessment_status'] = 'READY'; // Exact single-cell prepare-price fixture delta; baseline file stays immutable.
            $serialized = json_encode($row, JSON_THROW_ON_ERROR);
        }
    }
    unset($serialized);
    sort($baseline['assessment_participants'], SORT_STRING);
    foreach (['checkout_handoffs', 'checkout_sessions', 'audit_logs'] as $lifecycle) {
        if ($baseline[$lifecycle] !== []) {
            throw new RuntimeException('Lifecycle baseline was not empty.');
        }
        unset($baseline[$lifecycle], $actual[$lifecycle]);
    }
    if (! checkoutBrowserSnapshotMatches($baseline, $actual)) {
        throw new RuntimeException('Business rows changed.');
    }
    $allowedActions = ['checkout_handoff.issued', 'checkout_handoff.consumed', 'checkout_handoff.recovery_reissued',
        'checkout_session.established', 'checkout_session.revoked', 'checkout_session.expired'];
    foreach (DB::table('audit_logs')->get() as $audit) {
        $own = array_values(array_filter($fixtures, static fn ($row) => (string) $row['attempt'] === $audit->subject_id));
        if (count($own) !== 1 || $audit->branch_id !== $own[0]['organization']
            || $audit->subject_type !== AssessmentParticipant::class || ! in_array($audit->action, $allowedActions, true)
            || preg_match('/och1_|ocs1_|ocsrf1_|PRIVATE_/', $audit->context)) {
            throw new RuntimeException('Unexpected lifecycle audit.');
        }
    }
    foreach (['second', 'same-person', 'no-js'] as $alias) {
        if (DB::table('audit_logs')->where('subject_id', (string) $fixtures[$alias]['attempt'])
            ->where('action', 'checkout_session.revoked')->where('context->reason', 'LOGOUT')->count() !== 1) {
            throw new RuntimeException('Logout audit mismatch.');
        }
    }
    if (DB::table('checkout_sessions')->count() !== 9
        || DB::table('checkout_handoffs')->count() !== 11
        || DB::table('audit_logs')->count() !== 34
        || DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')->count() !== 9
        || DB::table('audit_logs')->where('action', 'checkout_session.revoked')->count() !== 4
        || DB::table('audit_logs')->where('action', 'checkout_session.expired')->count() !== 1
        || DB::table('audit_logs')->where('action', 'checkout_session.established')->count() !== 9
        || DB::table('audit_logs')->where('action', 'checkout_handoff.issued')->count() !== 10
        || DB::table('audit_logs')->where('action', 'checkout_handoff.recovery_reissued')->count() !== 1) {
        throw new RuntimeException('Lifecycle count mismatch.');
    }
    $states = ['first' => ['ACTIVE'], 'second' => ['REVOKED'], 'expiry' => ['EXPIRED'], 'revoke' => ['REVOKED'],
        'orphan' => ['REVOKED', 'ACTIVE'], 'foreign-origin' => [], 'wrong-host' => [], 'no-js' => ['REVOKED'],
        'price' => ['ACTIVE'], 'peer' => [], 'same-person' => ['REVOKED']];
    foreach ($states as $alias => $expected) {
        $sessions = DB::table('checkout_sessions')->where('assessment_participant_id', $fixtures[$alias]['attempt'])->orderBy('id')->get();
        if ($sessions->pluck('status')->all() !== $expected) {
            throw new RuntimeException('Per-fixture session state mismatch.');
        }
        foreach ($sessions as $session) {
            foreach (['organization_id' => 'organization', 'participant_id' => 'participant', 'package_id' => 'package', 'integration_client_id' => 'client'] as $column => $key) {
                if ($session->{$column} !== $fixtures[$alias][$key]) {
                    throw new RuntimeException('Per-fixture session scope mismatch.');
                }
            }
        }
    }
    if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
        throw new RuntimeException('Verifier context mismatch.');
    }
}
