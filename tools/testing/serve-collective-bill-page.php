<?php

declare(strict_types=1);

use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Enums\AdminRole;
use App\Filament\Actions\CreateCollectiveBillAction;
use App\Filament\Resources\AssessmentParticipants\Pages\CreateCollectiveBill;
use App\Models\Admin;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\AssessmentPreviewFixture as Fixture;

// Dedicated SQLite fixture only; neither workspace .env nor active caches are read.
$directory = getenv('ONCAM_COLLECTIVE_PAGE_DIRECTORY');
$root = dirname(__DIR__, 2);
if (! is_string($directory) || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
    || ! preg_match('/^oncam-collective-page-[a-f0-9]{32}$/D', basename($directory))
    || ! is_dir($directory) || is_link($directory) || file_exists($directory.'/.env')) {
    throw new RuntimeException('A new dedicated oncam-collective temporary directory without .env is required.');
}
$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : 'http';
if (! in_array($mode, ['init', 'verify', 'http'], true)) {
    throw new RuntimeException('Use init, verify, or the loopback PHP development server.');
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($mode === 'http') {
    if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1' || ($_SERVER['SERVER_PORT'] ?? '') !== '8012'
        || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8012'
        || ! in_array($_SERVER['HTTP_ORIGIN'] ?? '', ['', 'http://127.0.0.1:8012'], true)) {
        http_response_code(403);
        exit;
    }
    if (preg_match('#^/(?:js|css|fonts)/filament/#D', (string) $path) === 1) {
        $asset = realpath($root.'/public'.$path);
        $public = realpath($root.'/public');
        if ($asset !== false && $public !== false && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)) {
            return false;
        }
    }
    // No other application, admin, integration, payment or storage routes are reachable.
    if (! in_array($path, ['/preview', '/fixture-control', '/fixture.css', '/fixture-livewire.js', '/fixture-update', '/favicon.ico'], true)
        && ! preg_match('#^/admin/organization-bills/[0-9]+$#D', (string) $path)) {
        http_response_code(404);
        exit;
    }
    if ($path === '/favicon.ico') {
        http_response_code(204);
        exit;
    }
    if ($path === '/fixture.css') {
        header('Content-Type: text/css');
        header('Cache-Control: no-store');
        if (! is_file($directory.'/fixture.css')) {
            http_response_code(404);
        } else {
            readfile($directory.'/fixture.css');
        }
        exit;
    }
}
$database = $directory.'/browser.sqlite';
if ($mode === 'init') {
    if (file_exists($database) || file_exists($directory.'/manifest.json')) {
        throw new RuntimeException('Refusing to replace an existing fixture.');
    }
    touch($database);
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $storagePath) {
        mkdir($directory.'/storage/'.$storagePath, 0700, true);
    }
} elseif (! is_file($database) || ! is_file($directory.'/manifest.json')) {
    throw new RuntimeException('Initialize a new disposable fixture first.');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_NAME' => 'ONCAM collective page synthetic browser',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'APP_DEBUG' => 'false',
    'APP_URL' => 'http://127.0.0.1:8012', 'APP_CONFIG_CACHE' => $directory.'/config.php',
    'APP_ROUTES_CACHE' => $directory.'/routes.php', 'APP_SERVICES_CACHE' => $directory.'/services.php',
    'APP_PACKAGES_CACHE' => $directory.'/packages.php', 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'collective_page_'.basename($directory), 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4', 'XENDIT_SECRET_KEY' => '', 'XENDIT_CALLBACK_TOKEN' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';

final class SyntheticCollectiveAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        return 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2232%22 height=%2232%22%3E%3Crect width=%2232%22 height=%2232%22 fill=%22%2309090b%22/%3E%3C/svg%3E';
    }
}

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
        throw new RuntimeException('Unsafe fixture configuration.');
    }
    $config->set('database.connections', ['sqlite' => $config->get('database.connections.sqlite')]);
    $config->set('database.redis', []);
    $config->set('filesystems.disks', ['local' => ['driver' => 'local', 'root' => $app->storagePath('app/private')]]);
});
$app->booting(function (Application $app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
    Mail::fake();
});
$app->make(Kernel::class)->bootstrap();
Filament::getPanel('admin')->defaultAvatarProvider(SyntheticCollectiveAvatarProvider::class);

if ($mode === 'init') {
    if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Disposable migration failed.');
    }
    $organization = null;
    $packages = $ids = [];
    foreach (['A', 'A', 'B', 'B', 'C', 'C', 'A', 'B', 'C', 'A', 'LEGACY', 'FREE', 'SELF', 'CLAIMED'] as $index => $group) {
        $fixture = Fixture::create($organization === null ? null : ['organization' => $organization]);
        $organization = $fixture['organization'];
        if (! isset($packages[$group])) {
            $packages[$group] = $fixture['package'];
            DB::table('packages')->where('id', $fixture['package'])->update([
                'code' => 'SYN-'.$group, 'name' => 'Paket sintetis '.$group,
                'amount' => $group === 'FREE' ? 0 : ($group === 'C' ? 300 : ($group === 'B' ? 200 : 100)),
                'consultation_amount' => $group === 'B' ? 50 : 30,
            ]);
        }
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'package_id' => $packages[$group], 'assessment_attempt_id' => 'ATTEMPT-SYN-'.($index + 1),
            'external_candidate_id' => 'KANDIDAT-'.($index + 1).($index === 2 ? '-'.str_repeat('X', 64) : ''),
            'assessment_round_id' => 'Periode sintetis September',
            'metadata' => $group === 'LEGACY' ? null : '{"checkout_contract_version":"checkout-v2","private":"PRIVATE-SENTINEL"}',
        ]);
        DB::table('participants')->where('id', $fixture['participant'])->update([
            'full_name' => $index === 1 ? null : 'Peserta Sintetis '.($index + 1),
        ]);
        DB::table('integration_sources')->where('id', $fixture['source'])->update([
            'allowed_assessment_packages' => json_encode(['SYN-'.$group], JSON_THROW_ON_ERROR),
            'allowed_payer_types' => $group === 'SELF' ? '["self"]' : '["self","organization"]',
        ]);
        $ids[] = $fixture['attempt'];
    }
    $admin = Admin::create(['name' => 'Admin sintetis', 'email' => 'collective-browser@example.test',
        'password' => bin2hex(random_bytes(24)), 'role' => AdminRole::BranchAdmin,
        'branch_id' => $organization, 'can_verify_payments' => false]);
    $method = DB::table('payment_methods')->insertGetId(['code' => 'manual_transfer', 'display_name' => 'Transfer sintetis', 'is_active' => true]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::auth()->login($admin);
    $claimedSelection = [['assessmentParticipantId' => $ids[13], 'consultationRequested' => false]];
    $claimedPreview = app(CreateCollectiveBillAction::class)->preview($claimedSelection);
    $claimedBill = app(CreateCollectiveBillAction::class)->confirm($claimedSelection, $method, $claimedPreview['selectionHash']);
    Filament::auth()->logout();
    $foreign = Fixture::create();
    $foreignAdmin = Admin::create(['name' => 'Admin cabang asing', 'email' => 'foreign-browser@example.test',
        'password' => bin2hex(random_bytes(24)), 'role' => AdminRole::BranchAdmin,
        'branch_id' => $foreign['organization'], 'can_verify_payments' => false]);
    $control = bin2hex(random_bytes(24));
    file_put_contents($directory.'/manifest.json', json_encode(['admin' => $admin->id, 'foreignAdmin' => $foreignAdmin->id,
        'organization' => $organization, 'foreignOrganization' => $foreign['organization'], 'ids' => $ids,
        'method' => $method, 'control' => $control, 'baselineBill' => $claimedBill->id,
        'foreignBill' => $foreign['bill']], JSON_THROW_ON_ERROR));
    echo "Disposable synthetic fixture initialized; no login to real accounts.\n";
    exit;
}
if ($mode === 'verify') {
    $counts = [];
    foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
        'audit_logs', 'outbox_messages', 'orders', 'entitlements', 'consent_records', 'identity_verifications'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $expected = [
        'assessment_charges' => 11,
        'assessment_bills' => 2,
        'assessment_bill_items' => 11,
        'assessment_entitlements' => 0,
        'audit_logs' => 2,
        'outbox_messages' => 0,
        'orders' => 0,
        'entitlements' => 0,
        'consent_records' => 0,
        'identity_verifications' => 0,
    ];
    $canonical = DB::table('assessment_bills')->where('id', $manifest['baselineBill'] ?? 0)->exists();
    echo json_encode(['counts' => $counts, 'expected' => $expected, 'baselineBillStillExists' => $canonical], JSON_THROW_ON_ERROR)."\n";
    exit($counts === $expected && $canonical ? 0 : 1);
}

$manifestJson = file_get_contents($directory.'/manifest.json');
if ($manifestJson === false) {
    throw new RuntimeException('Fixture manifest cannot be read.');
}
$manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
if (! is_array($manifest) || ! is_int($manifest['admin'] ?? null) || $manifest['admin'] < 1
    || ! is_array($manifest['ids'] ?? null) || ! array_is_list($manifest['ids']) || $manifest['ids'] === []) {
    throw new RuntimeException('Invalid synthetic manifest.');
}
$adminId = $manifest['admin'];
$attemptIds = $manifest['ids'];
$control = $manifest['control'] ?? null;
if (! is_string($control) || preg_match('/^[a-f0-9]{48}$/D', $control) !== 1) {
    throw new RuntimeException('Invalid synthetic control token.');
}
foreach ($attemptIds as $id) {
    if (! is_int($id) || $id < 1) {
        throw new RuntimeException('Invalid synthetic attempt ID.');
    }
}
Filament::setCurrentPanel(Filament::getPanel('admin'));
Livewire::component('collective-page-fixture', CreateCollectiveBill::class);
Livewire::setUpdateRoute(fn ($handler) => Route::post('/fixture-update', $handler)->middleware('web'));
Livewire::setScriptRoute(fn ($handler) => Route::get('/fixture-livewire.js', $handler));
Route::middleware('web')->get('/preview', function () use ($adminId): string {
    $as = request()->query('as', 'branch');
    if ($as !== 'branch') {
        Filament::auth()->logout();
        abort(403);
    }
    $admin = Admin::findOrFail($adminId);
    abort_unless($admin->email === 'collective-browser@example.test', 403);
    Filament::auth()->login($admin);

    return Blade::render(<<<'BLADE'
        <!doctype html><html lang="id"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>ONCAM · collective page fixture</title><link rel="stylesheet" href="/fixture.css">
        @livewireStyles
        {!! \Filament\Support\Facades\FilamentAsset::renderStyles([]) !!}
        <style>body.fixture-body{font-family:system-ui,sans-serif;margin:0}.fixture-main{box-sizing:border-box;max-width:60rem;margin:auto;padding:1rem}</style>
        </head><body class="fixture-body"><main class="fixture-main"><livewire:collective-page-fixture /></main>@filamentScripts(withCore: true)</body></html>
        BLADE, deleteCachedView: true);
});
Route::post('/fixture-control', function () use ($manifest, $control): array {
    abort_unless(request()->header('X-Oncam-Fixture') === $control, 404);

    return match (request()->string('action')->toString()) {
        'price-up' => tap(['ok' => true], fn () => DB::table('packages')->where('id',
            DB::table('assessment_participants')->where('id', $manifest['ids'][0])->value('package_id'))->update(['amount' => 999])),
        'price-restore' => tap(['ok' => true], fn () => DB::table('packages')->where('id',
            DB::table('assessment_participants')->where('id', $manifest['ids'][0])->value('package_id'))->update(['amount' => 100])),
        'role-off' => tap(['ok' => true], fn () => DB::table('admins')->where('id', $manifest['admin'])->update(['role' => AdminRole::Staff->value])),
        'role-on' => tap(['ok' => true], fn () => DB::table('admins')->where('id', $manifest['admin'])->update(['role' => AdminRole::BranchAdmin->value])),
        'tenant-off' => tap(['ok' => true], fn () => DB::table('admins')->where('id', $manifest['admin'])->update(['branch_id' => $manifest['foreignOrganization']])),
        'tenant-on' => tap(['ok' => true], fn () => DB::table('admins')->where('id', $manifest['admin'])->update(['branch_id' => $manifest['organization']])),
        default => abort(422),
    };
});
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; font-src 'self'; frame-src 'none'; form-action 'self'");
$app->handleRequest(Request::capture());
