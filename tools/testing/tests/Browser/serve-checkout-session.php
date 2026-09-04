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
$integrityFailure = null;
set_exception_handler(static function (Throwable $error) use (&$integrityFailure): void {
    if ($integrityFailure !== null) {
        $integrityFailure();
    }
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
    $checks[] = checkoutBrowserDiagnosticRecord('file_hash_progress', 5000, 1000000000, 90) === [
        'stage' => 'file_hash_progress', 'elapsedSeconds' => 1.0, 'processedCount' => 5000, 'executionLimit' => 90,
    ];
    foreach ([['arbitrary/path', 0, 0, 90], ['tree_start', -1, 0, 90],
        ['tree_start', 50001, 0, 90], ['tree_start', 0, -1, 90], ['tree_start', 0, 0, -1]] as $invalidRecord) {
        try {
            checkoutBrowserDiagnosticRecord(...$invalidRecord);
            $checks[] = false;
        } catch (InvalidArgumentException) {
            $checks[] = true;
        }
    }
    $diagnosticTestManifest = ['vendor/example/Library.php' => str_repeat('a', 64)];
    $checks[] = checkoutBrowserDiagnosticError(['type' => 2, 'file' => 'C:\\synthetic\\source\\vendor\\example\\Library.php',
        'line' => 42, 'message' => 'NEVER_EXPORT'], 'bootstrap_end', 'C:/synthetic/source', $diagnosticTestManifest) === [
            'errorType' => 2, 'lastStage' => 'bootstrap_end', 'sourceFile' => 'vendor/example/Library.php', 'line' => 42,
        ];
    foreach (['C:/outside/private.php', 'C:/synthetic/source-other/vendor/example/Library.php',
        'C:/synthetic/source/../private.php', 'C:/synthetic/source/vendor/../example/Library.php',
        'C:/synthetic/source/vendor/./example/Library.php', 'C:/synthetic/source/vendor/unmanifested.php',
        'C:/synthetic/source/C:/private.php', 'C:/synthetic/source/vendor/%2e%2e/private.php',
        'vendor/example/Library.php', 'C:/synthetic/source//vendor/example/Library.php'] as $unsafeErrorFile) {
        $checks[] = checkoutBrowserDiagnosticError(['type' => 2, 'file' => $unsafeErrorFile, 'line' => 3],
            'tree_start', 'C:/synthetic/source', $diagnosticTestManifest)['sourceFile'] === 'outside-source';
    }
    $checks[] = checkoutBrowserDiagnosticError(null, 'tree_start', 'C:/synthetic/source', []) === [
        'errorType' => null, 'lastStage' => 'tree_start', 'sourceFile' => 'outside-source', 'line' => 0,
    ];
    if (in_array(false, $checks, true)) {
        fwrite(STDERR, "Harness pure checks failed.\n");
        exit(1);
    }
    echo json_encode(['pureChecks' => count($checks), 'passed' => true], JSON_THROW_ON_ERROR)."\n";
    exit;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--integrity-tests') {
    require __DIR__.'/checkout-integrity-tests.php';
    exit;
}

// Disposable browser-only fixture. It refuses a workspace .env and all non-loopback clients.
$directory = getenv('ONCAM_CHECKOUT_BROWSER_DIRECTORY');
$port = getenv('ONCAM_CHECKOUT_BROWSER_PORT');
$cooperativeIntegrity = is_string($directory)
    ? checkoutBrowserIntegrityMode($directory, getenv('ONCAM_CHECKOUT_BROWSER_INTEGRITY_MODE')) : false;
if ($cooperativeIntegrity) {
    $integrityFailure = static fn () => checkoutBrowserIntegrityInvalidate($directory);
    register_shutdown_function(static function () use ($integrityFailure): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $integrityFailure();
        }
    });
}
$temporaryRoot = realpath(sys_get_temp_dir());
if (! is_string($directory) || realpath(dirname($directory)) !== $temporaryRoot
    || ! preg_match('/^oncam-checkout-[a-f0-9]{32}$/D', basename($directory))
    || ! is_dir($directory) || file_exists($directory.'/.env')
    || $port !== '8126') {
    throw new RuntimeException('A fresh checkout browser directory and bounded port are required.');
}

$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : 'serve';
if (! in_array($mode, ['init', 'verify', 'serve', 'integrity-pre', 'integrity-start', 'integrity-stop', 'integrity-post'], true)
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
$diagnostic = static function (string $stage, int $count = 0): void {};
if (getenv('ONCAM_CHECKOUT_BROWSER_STAGE_DIAGNOSTICS') === '1') {
    // Existing, canonical direct temp child only; exclusive creation refuses linked/existing output.
    if ($mode !== 'serve' || ! is_file($directory.'/browser.sqlite') || is_link($directory)
        || strcasecmp(str_replace('\\', '/', (string) realpath($directory)), str_replace('\\', '/', $directory)) !== 0) {
        throw new RuntimeException('Unsafe diagnostic directory.');
    }
    $diagnosticHandle = fopen($directory.'/diagnostic-stages.jsonl', 'x');
    if ($diagnosticHandle === false) {
        throw new RuntimeException('Diagnostic output must be new.');
    }
    $diagnosticStart = hrtime(true);
    $diagnosticRecords = 0;
    $diagnosticLastStage = 'tree_start';
    $diagnostic = static function (string $stage, int $count = 0) use ($diagnosticHandle, $diagnosticStart, &$diagnosticRecords, &$diagnosticLastStage, $root, &$manifest): void {
        $lastError = error_get_last();
        if (++$diagnosticRecords > 64) {
            throw new RuntimeException('Diagnostic record bound exceeded.');
        }
        $record = checkoutBrowserDiagnosticRecord($stage, $count, hrtime(true) - $diagnosticStart, (int) ini_get('max_execution_time'));
        $record['lastError'] = checkoutBrowserDiagnosticError($lastError, $diagnosticLastStage, $root, is_array($manifest) ? $manifest : []);
        $diagnosticLastStage = $stage;
        fwrite($diagnosticHandle, json_encode($record, JSON_THROW_ON_ERROR)."\n");
        fflush($diagnosticHandle);
    };
    register_shutdown_function(static function () use ($diagnosticHandle, &$diagnosticLastStage, $root, &$manifest): void {
        $error = error_get_last();
        fwrite($diagnosticHandle, json_encode([
            'stage' => 'shutdown',
            'lastError' => checkoutBrowserDiagnosticError($error, $diagnosticLastStage, $root, is_array($manifest) ? $manifest : []),
            'maximumExecutionTimeExhausted' => $error !== null
                && preg_match('/^Maximum execution time of \d+ seconds exceeded/', $error['message']) === 1,
        ], JSON_THROW_ON_ERROR)."\n");
        fclose($diagnosticHandle);
    });
}
$manifestDigest = getenv('ONCAM_CHECKOUT_BROWSER_MANIFEST_SHA256');
if (! is_string($manifestDigest)) {
    throw new RuntimeException('Manifest digest required.');
}
$fullIntegrity = static fn () => checkoutBrowserIntegrityFull($directory, $manifestDigest, $diagnostic);
if ($cooperativeIntegrity && $mode !== 'init') {
    $integrityOwner = json_decode((string) getenv('ONCAM_CHECKOUT_BROWSER_OWNER'), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($integrityOwner)) {
        throw new RuntimeException('Owner identity required.');
    }
    $integrityOwned = $mode === 'integrity-start'
        ? json_decode((string) getenv('ONCAM_CHECKOUT_BROWSER_OWNED_PROCESSES'), true, 8, JSON_THROW_ON_ERROR) : [];
    if (! is_array($integrityOwned)) {
        throw new RuntimeException('Runtime identities required.');
    }
    $integrityResult = checkoutBrowserIntegrityOperation($directory, $manifestDigest, $integrityOwner,
        str_starts_with($mode, 'integrity-') ? substr($mode, 10) : $mode,
        $fullIntegrity, checkoutBrowserIntegrityProcess(...), $integrityOwned,
        getenv('ONCAM_CHECKOUT_BROWSER_ASSERTIONS_PASSED') === '1', in_array($mode, ['serve', 'verify'], true) ? getmypid() : null);
    $manifest = $integrityResult['manifest'];
    if (str_starts_with($mode, 'integrity-')) {
        echo json_encode(['state' => $integrityResult['state'], 'accepted' => false], JSON_THROW_ON_ERROR)."\n";
        exit;
    }
} else {
    if (str_starts_with($mode, 'integrity-')) {
        throw new RuntimeException('Cooperative mode required.');
    }
    $manifest = $fullIntegrity();
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

$diagnostic('autoload_start');
require $root.'/vendor/autoload.php';
$diagnostic('autoload_end');

$diagnostic('application_create_start');
$app = require $root.'/bootstrap/app.php';
$diagnostic('application_create_end');
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
$diagnostic('bootstrap_start');
$app->make(Kernel::class)->bootstrap();
$diagnostic('bootstrap_end');
// Laravel installs its own global handler during bootstrap; control/CLI errors must stay opaque too.
set_exception_handler(static function (Throwable $error) use ($directory, $integrityFailure): void {
    if ($integrityFailure !== null) {
        $integrityFailure();
    }
    file_put_contents($directory.'/violations.txt', "HARNESS_EXCEPTION\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo "Checkout browser harness refused.\n";
    exit(1);
});
app(ExceptionHandler::class)->reportable(static function (Throwable $error) use ($directory, $integrityFailure): bool {
    if ($integrityFailure !== null) {
        $integrityFailure();
    }
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
    if ($cooperativeIntegrity) {
        checkoutBrowserIntegrityOperation($directory, $manifestDigest, $integrityOwner, 'business',
            $fullIntegrity, checkoutBrowserIntegrityProcess(...), serverPid: getmypid());
    }
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

$diagnostic('request_baseline_start');
$requestBaseline = checkoutBrowserRows();
$diagnostic('request_baseline_end');
$clockReads = 0;
$idleWrites = 0;
DB::listen(static function (QueryExecuted $query) use (&$clockReads, &$idleWrites): void {
    $clockReads += str_contains($query->sql, 'AS current_time') ? 1 : 0;
    $idleWrites += str_starts_with($query->sql, 'update "checkout_sessions"') ? 1 : 0;
});
$diagnostic('request_handle_start');
$app->handleRequest(Request::capture());
$diagnostic('request_handle_end');
$diagnostic('postconditions_start');
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
$diagnostic('postconditions_end');

function checkoutBrowserDiagnosticRecord(string $stage, int $count, int $elapsedNanoseconds, int $limit): array
{
    if (! in_array($stage, ['tree_start', 'tree_end', 'manifest_start', 'manifest_end',
        'file_hash_start', 'file_hash_progress', 'file_hash_end', 'inventory_start', 'inventory_end',
        'required_start', 'required_end', 'autoload_start', 'autoload_end',
        'application_create_start', 'application_create_end', 'bootstrap_start', 'bootstrap_end',
        'request_baseline_start', 'request_baseline_end', 'request_handle_start', 'request_handle_end',
        'postconditions_start', 'postconditions_end'], true)
        || $count < 0 || $count > 50000 || $elapsedNanoseconds < 0 || $limit < 0) {
        throw new InvalidArgumentException('Invalid diagnostic record.');
    }

    return ['stage' => $stage, 'elapsedSeconds' => round($elapsedNanoseconds / 1e9, 4),
        'processedCount' => $count, 'executionLimit' => $limit];
}

function checkoutBrowserDiagnosticError(?array $error, string $lastStage, string $root, array $manifest): array
{
    checkoutBrowserDiagnosticRecord($lastStage, 0, 0, 0);
    $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
    $file = is_string($error['file'] ?? null) ? str_replace('\\', '/', $error['file']) : '';
    $relative = strncasecmp($file, $prefix, strlen($prefix)) === 0 ? substr($file, strlen($prefix)) : '';
    $safeFile = checkoutBrowserSafeRelative($relative) && array_key_exists($relative, $manifest)
        ? $relative : 'outside-source';

    return ['errorType' => is_int($error['type'] ?? null) ? $error['type'] : null,
        'lastStage' => $lastStage, 'sourceFile' => $safeFile,
        'line' => is_int($error['line'] ?? null) ? max(0, $error['line']) : 0];
}

function checkoutBrowserSafeRelative(string $path): bool
{
    return preg_match('#^[a-zA-Z0-9_@.-]+(?:/[a-zA-Z0-9_@.-]+)*$#D', $path) === 1
        && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true);
}

/** Fixed reviewed surface, not a caller-selected or automatically expanding set. */
function checkoutBrowserIntegrityCriticalFiles(): array
{
    return [
        'composer.json', 'composer.lock', 'vendor/autoload.php',
        'vendor/composer/autoload_real.php', 'vendor/composer/autoload_static.php',
        'vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_files.php',
        'vendor/composer/autoload_namespaces.php', 'vendor/composer/autoload_psr4.php',
        'vendor/composer/ClassLoader.php', 'vendor/composer/platform_check.php',
        'vendor/composer/installed.php', 'vendor/composer/installed.json',
        'bootstrap/app.php', 'bootstrap/providers.php',
        'config/app.php', 'config/assessment_billing.php', 'config/assessment_integration.php',
        'config/auth.php', 'config/cache.php', 'config/consent.php', 'config/database.php',
        'config/filesystems.php', 'config/fortify.php', 'config/identity.php', 'config/inertia.php',
        'config/logging.php', 'config/mail.php', 'config/participant_auth.php',
        'config/participant_notifications.php', 'config/payments.php', 'config/queue.php',
        'config/referral.php', 'config/selection_integration.php', 'config/services.php', 'config/session.php',
        'app/Providers/AppServiceProvider.php',
        'app/Http/Controllers/CheckoutSessionController.php', 'resources/views/checkout/summary.blade.php',
        'resources/views/checkout/private.blade.php',
        'app/Http/Middleware/AuthenticateCheckoutSession.php',
        'app/Http/Middleware/VerifyCheckoutSessionMutation.php',
        'app/Http/Middleware/ProtectCheckoutSessionHttpBoundary.php',
        'app/Actions/Integrations/CheckoutSessionLifecycle.php',
        'app/Actions/Integrations/EstablishCheckoutSession.php',
        'app/Actions/Integrations/ConsumeCheckoutHandoff.php',
        'app/Actions/Integrations/IssueCheckoutHandoff.php',
        'app/Services/Integrations/ConsumeCheckoutHandoffTransaction.php',
        'app/Services/Integrations/CheckoutHandoffHistoryValidator.php',
        'app/Services/Integrations/CheckoutSessionHttpContract.php',
        'app/Services/Integrations/CheckoutSummaryComposer.php',
        'app/Services/Integrations/CheckoutProfileMapper.php',
        'app/Services/Integrations/CheckoutPaymentFactsReader.php',
        'app/Data/Integrations/CheckoutSummary.php', 'app/Data/Integrations/CheckoutProfile.php',
        'app/Data/Integrations/CheckoutPaymentFacts.php', 'app/Data/Integrations/CheckoutProductPaymentFacts.php',
        'app/Data/Integrations/CheckoutSessionPrincipal.php', 'app/Data/Integrations/CheckoutSessionSelector.php',
        'app/Data/Integrations/CheckoutSessionMutationCredentials.php',
        'app/Data/Integrations/CheckoutSessionExchangeInput.php', 'app/Data/Integrations/EstablishedCheckoutSession.php',
        'app/Services/ParticipantAuth/AssessmentEntitlementGate.php',
        'app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php',
        'app/Services/ParticipantAuth/AcceptedConsentReader.php',
        'app/Services/Payments/AssessmentSettlementReader.php',
        'tools/testing/tests/Browser/serve-checkout-session.php',
        'tools/testing/tests/Browser/checkout-session.browser.mjs',
        'tools/testing/tests/Browser/https-loopback-proxy.py',
    ];
}

/** A marked run cannot downgrade to the old full-per-request mode. */
function checkoutBrowserIntegrityMode(string $directory, string|false $setting): bool
{
    $marked = file_exists($directory.'/integrity-evidence.json') || is_link($directory.'/integrity-evidence.json')
        || file_exists($directory.'/integrity-invalid') || is_link($directory.'/integrity-invalid');
    if (! in_array($setting, [false, '', 'cooperative-v1'], true) || ($marked && $setting !== 'cooperative-v1')) {
        checkoutBrowserIntegrityInvalidate($directory);
        throw new RuntimeException('Integrity mode cannot change');
    }

    return $setting === 'cooperative-v1';
}

function checkoutBrowserIntegrityPaths(string $directory): void
{
    if (realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || preg_match('/^oncam-checkout-[a-f0-9]{32}$/D', basename($directory)) !== 1) {
        throw new RuntimeException('Invalid integrity run');
    }
    foreach ([$directory, $directory.'/source'] as $path) {
        checkoutBrowserIntegrityCanonical($path);
    }
    if (glob($directory.'/.env*') !== [] || glob($directory.'/source/.env*') !== []
        || file_exists($directory.'/config.php') || file_exists($directory.'/routes.php')
        || array_diff(glob($directory.'/source/bootstrap/cache/*') ?: [], [$directory.'/source/bootstrap/cache/.gitignore']) !== []) {
        throw new RuntimeException('Unsafe integrity environment');
    }
    foreach (['browser.sqlite', 'storage', 'storage/framework', 'storage/framework/sessions',
        'storage/framework/views', 'storage/public', 'source-manifest.json'] as $relative) {
        if (file_exists($directory.'/'.$relative) || is_link($directory.'/'.$relative)) {
            checkoutBrowserIntegrityCanonical($directory.'/'.$relative);
        }
    }
}

function checkoutBrowserIntegrityCanonical(string $path): void
{
    $real = realpath($path);
    if ($real === false || is_link($path)
        || strcasecmp(str_replace('\\', '/', $real), str_replace('\\', '/', $path)) !== 0) {
        throw new RuntimeException('Noncanonical integrity path');
    }
}

function checkoutBrowserIntegrityInvalidate(string $directory): void
{
    // Safe even on an early failure: never follow an unvalidated run or existing link.
    if (realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || preg_match('/^oncam-checkout-[a-f0-9]{32}$/D', basename($directory)) !== 1) {
        return;
    }
    try {
        checkoutBrowserIntegrityCanonical($directory);
        $handle = @fopen($directory.'/integrity-invalid', 'x');
        if ($handle !== false) {
            fwrite($handle, "INVALID\n");
            fclose($handle);
        }
    } catch (Throwable) {
        // No repair, alternate location, or diagnostic payload on an invalid path.
    }
}

/** Return Windows process creation ticks, never process command lines or environment. */
function checkoutBrowserIntegrityProcess(int $pid): ?string
{
    if ($pid < 1 || PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Unsupported owner process');
    }
    $shell = getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe';
    $command = '$p=Get-Process -Id '.$pid.' -ErrorAction SilentlyContinue; if($p){$p.StartTime.ToUniversalTime().Ticks.ToString()}';
    $process = proc_open([$shell, '-NoProfile', '-NonInteractive', '-Command', $command],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        options: ['bypass_shell' => true, 'create_new_console' => false]);
    if (! is_resource($process)) {
        throw new RuntimeException('Owner inspection unavailable');
    }
    fclose($pipes[0]);
    $value = trim((string) stream_get_contents($pipes[1]));
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '' || ($value !== '' && preg_match('/^[0-9]{1,20}$/D', $value) !== 1)) {
        throw new RuntimeException('Owner inspection failed');
    }

    return $value === '' ? null : $value;
}

function checkoutBrowserIntegrityIdentity(array $identity): bool
{
    return array_keys($identity) === ['pid', 'started'] && is_int($identity['pid']) && $identity['pid'] > 0
        && is_string($identity['started']) && preg_match('/^[0-9]{1,20}$/D', $identity['started']) === 1;
}

/** Advisory cooperative owner; PID reuse is distinguished by creation ticks. */
function checkoutBrowserIntegrityOperation(string $directory, string $digest, array $owner, string $operation,
    callable $full, callable $probe, array $owned = [], bool $assertionsPassed = false, ?int $serverPid = null): array
{
    $handle = null;
    try {
        checkoutBrowserIntegrityPaths($directory);
        if (file_exists($directory.'/integrity-invalid') || is_link($directory.'/integrity-invalid')
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || array_keys($owner) !== ['pid', 'started', 'nonce']
            || ! checkoutBrowserIntegrityIdentity(['pid' => $owner['pid'], 'started' => $owner['started']])
            || ! is_string($owner['nonce']) || preg_match('/^[a-f0-9]{64}$/D', $owner['nonce']) !== 1
            || $probe($owner['pid']) !== $owner['started']) {
            throw new RuntimeException('Invalid or stale evidence owner');
        }
        $path = $directory.'/integrity-evidence.json';
        if ($operation !== 'pre') {
            checkoutBrowserIntegrityCanonical($path);
        }
        $handle = @fopen($path, $operation === 'pre' ? 'x+' : 'r+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Evidence ownership conflict');
        }
        if ($operation === 'pre') {
            $manifest = $full();
            checkoutBrowserIntegrityLight($directory, $digest);
            $record = ['version' => 1, 'run' => basename($directory), 'digest' => $digest, 'owner' => $owner,
                'state' => 'preverified', 'owned' => [], 'browserPassed' => false, 'businessVerified' => false, 'verifier' => null];
        } else {
            $record = json_decode((string) stream_get_contents($handle), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($record) || array_keys($record) !== ['version', 'run', 'digest', 'owner', 'state', 'owned', 'browserPassed', 'businessVerified', 'verifier']
                || $record['version'] !== 1 || $record['run'] !== basename($directory) || $record['digest'] !== $digest
                || $record['owner'] !== $owner || ! in_array($record['state'], ['preverified', 'serving', 'stopped', 'postverified'], true)
                || ! is_array($record['owned']) || ! is_bool($record['browserPassed']) || ! is_bool($record['businessVerified'])) {
                throw new RuntimeException('Invalid evidence record');
            }
            $manifest = checkoutBrowserIntegrityLight($directory, $digest);
            if ($operation === 'start' && $record['state'] === 'preverified') {
                if (array_keys($owned) !== ['php', 'tls', 'browser']) {
                    throw new RuntimeException('Runtime identities required');
                }
                $pids = [$owner['pid']];
                foreach ($owned as $identity) {
                    if (! is_array($identity) || ! checkoutBrowserIntegrityIdentity($identity)
                        || in_array($identity['pid'], $pids, true) || $probe($identity['pid']) !== $identity['started']) {
                        throw new RuntimeException('Invalid runtime identity');
                    }
                    $pids[] = $identity['pid'];
                }
                $record['owned'] = $owned;
                $record['state'] = 'serving';
            } elseif ($operation === 'serve' && $record['state'] === 'serving') {
                checkoutBrowserIntegrityOwned($record['owned'], $probe, true);
                if ($serverPid !== $record['owned']['php']['pid']) {
                    throw new RuntimeException('Wrong serving process');
                }
            } elseif (in_array($operation, ['stop', 'verify', 'business', 'post'], true)) {
                checkoutBrowserIntegrityOwned($record['owned'], $probe, false);
                if ($operation === 'stop' && $record['state'] === 'serving' && $assertionsPassed) {
                    $record['state'] = 'stopped';
                    $record['browserPassed'] = true; // Explicit cooperative runner attestation, not browser auth.
                } elseif ($record['state'] === 'stopped' && $record['browserPassed']) {
                    if ($operation === 'verify' && $record['verifier'] === null && $serverPid !== null) {
                        $started = $probe($serverPid);
                        $identity = ['pid' => $serverPid, 'started' => $started];
                        if (! checkoutBrowserIntegrityIdentity($identity) || $serverPid === $owner['pid']
                            || in_array($serverPid, array_column($record['owned'], 'pid'), true)) {
                            throw new RuntimeException('Invalid verifier identity');
                        }
                        $record['verifier'] = $identity;
                    } elseif ($operation === 'business' && is_array($record['verifier'])
                        && checkoutBrowserIntegrityIdentity($record['verifier'])
                        && $serverPid === $record['verifier']['pid']
                        && $probe($serverPid) === $record['verifier']['started'] && ! $record['businessVerified']) {
                        $record['businessVerified'] = true;
                    } elseif ($operation === 'post' && $record['businessVerified']) {
                        if (! is_array($record['verifier']) || ! checkoutBrowserIntegrityIdentity($record['verifier'])
                            || $probe($record['verifier']['pid']) === $record['verifier']['started']) {
                            throw new RuntimeException('Verifier cleanup required');
                        }
                        $manifest = $full();
                        if (file_exists($directory.'/violations.txt')) {
                            throw new RuntimeException('Recorded test violation');
                        }
                        $record['state'] = 'postverified';
                    } else {
                        throw new RuntimeException('Evidence transition refused');
                    }
                } else {
                    throw new RuntimeException('Evidence transition refused');
                }
            } else {
                throw new RuntimeException('Evidence transition refused');
            }
        }
        // Full scans can outlive an owner; check again before publishing either boundary.
        if (in_array($operation, ['pre', 'post'], true)) {
            if ($probe($owner['pid']) !== $owner['started'] || file_exists($directory.'/integrity-invalid')) {
                throw new RuntimeException('Owner lost during verification');
            }
            if ($operation === 'post') {
                checkoutBrowserIntegrityOwned($record['owned'], $probe, false);
                if ($probe($record['verifier']['pid']) === $record['verifier']['started']) {
                    throw new RuntimeException('Verifier cleanup changed');
                }
            }
        }
        rewind($handle);
        $encoded = json_encode($record, JSON_THROW_ON_ERROR);
        if (! ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || ! fflush($handle)) {
            throw new RuntimeException('Evidence persistence failed');
        }

        return ['manifest' => $manifest, 'state' => $record['state']];
    } catch (Throwable $error) {
        checkoutBrowserIntegrityInvalidate($directory);
        throw $error;
    } finally {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

function checkoutBrowserIntegrityOwned(array $owned, callable $probe, bool $alive): void
{
    if (array_keys($owned) !== ['php', 'tls', 'browser']) {
        throw new RuntimeException('Missing cleanup identities');
    }
    foreach ($owned as $identity) {
        if (! is_array($identity) || ! checkoutBrowserIntegrityIdentity($identity)
            || (($probe($identity['pid']) === $identity['started']) !== $alive)) {
            throw new RuntimeException('Runtime process state mismatch');
        }
    }
}

function checkoutBrowserIntegrityLight(string $directory, string $digest): array
{
    checkoutBrowserIntegrityPaths($directory);
    $bytes = (string) file_get_contents($directory.'/source-manifest.json');
    if (! hash_equals($digest, hash('sha256', $bytes))) {
        throw new RuntimeException('Manifest mismatch');
    }
    $manifest = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || count($manifest) < 10 || count($manifest) > 50000) {
        throw new RuntimeException('Invalid source manifest');
    }
    foreach (checkoutBrowserIntegrityCriticalFiles() as $relative) {
        $path = $directory.'/source/'.$relative;
        for ($parent = dirname($path); $parent !== $directory; $parent = dirname($parent)) {
            checkoutBrowserIntegrityCanonical($parent);
        }
        checkoutBrowserIntegrityCanonical($path);
        if (! isset($manifest[$relative]) || ! is_string($manifest[$relative])
            || ! hash_equals($manifest[$relative], hash_file('sha256', $path))) {
            throw new RuntimeException('Critical source mismatch');
        }
    }

    return $manifest;
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

function checkoutBrowserIntegrityFull(string $directory, string $manifestDigest, callable $diagnostic): array
{
    checkoutBrowserIntegrityPaths($directory);
    $root = $directory.'/source';
    $diagnostic('tree_start');
    checkoutBrowserAssertTree($directory);
    $diagnostic('tree_end');
    $diagnostic('manifest_start');
    $manifest = json_decode((string) file_get_contents($directory.'/source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (! is_string($manifestDigest) || preg_match('/^[a-f0-9]{64}$/D', $manifestDigest) !== 1
        || ! hash_equals($manifestDigest, hash_file('sha256', $directory.'/source-manifest.json'))) {
        throw new RuntimeException('Reviewed manifest digest required.');
    }
    if (! is_array($manifest) || count($manifest) < 10 || count($manifest) > 50000) {
        throw new RuntimeException('Source manifest required.');
    }
    $diagnostic('manifest_end', count($manifest));
    $diagnostic('file_hash_start');
    $diagnosticHashed = 0;
    foreach ($manifest as $relative => $digest) {
        if (! is_string($relative) || ! checkoutBrowserSafeRelative($relative)
            || ! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)
            || ! is_file($root.'/'.$relative) || ! hash_equals($digest, hash_file('sha256', $root.'/'.$relative))) {
            throw new RuntimeException('Source manifest mismatch.');
        }
        if (++$diagnosticHashed % 5000 === 0) {
            $diagnostic('file_hash_progress', $diagnosticHashed);
        }
    }
    $diagnostic('file_hash_end', $diagnosticHashed);
    $diagnostic('inventory_start');
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
    $diagnostic('inventory_end', count($inventory));
    $diagnostic('required_start');
    foreach (['composer.lock', 'vendor/autoload.php', 'app/Http/Controllers/CheckoutSessionController.php',
        'resources/views/checkout/summary.blade.php', 'app/Actions/Integrations/CheckoutSessionLifecycle.php',
        'app/Services/Integrations/CheckoutSummaryComposer.php', 'app/Data/Integrations/CheckoutSummary.php',
        'tools/testing/tests/Browser/serve-checkout-session.php', 'tools/testing/tests/Browser/checkout-session.browser.mjs',
        'tests/Support/AssessmentAccessFixture.php', 'tests/Support/AssessmentBillingFixture.php'] as $required) {
        if (! array_key_exists($required, $manifest)) {
            throw new RuntimeException('Incomplete source manifest.');
        }
    }
    $diagnostic('required_end');

    return $manifest;
}
