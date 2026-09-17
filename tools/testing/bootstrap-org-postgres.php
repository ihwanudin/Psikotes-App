<?php

declare(strict_types=1);

use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

$runId = getenv('ORG_TEST_RUN_ID');
if (! is_file('/.dockerenv') || ! is_string($runId) || ! preg_match('/^[a-f0-9]{32}$/D', $runId)) {
    throw new RuntimeException('Use the disposable organization PostgreSQL runner; direct execution is refused.');
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $directory) {
    $path = $app->storagePath($directory);
    if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
        throw new RuntimeException('Unable to initialize disposable storage.');
    }
}
// The real .env and host caches must never configure this application.
$app->useEnvironmentPath('/tmp/organization-payment-no-env');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $config = $app->make('config');
    if ($config->get('app.env') !== 'testing' || $app->configurationIsCached()) {
        throw new RuntimeException('PostgreSQL runner requires uncached testing configuration.');
    }
    $config->set('database.default', 'pgsql');
    $config->set('database.connections', ['pgsql' => [
        'driver' => 'pgsql', 'host' => 'org-test-db', 'port' => 5432,
        'database' => 'psikotes_organization_test', 'username' => 'org_test_owner',
        'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        'sslmode' => 'disable',
    ]]);
    $config->set('database.redis', []);
    $config->set('cache.default', 'array');
    $config->set('queue.default', 'sync');
    $config->set('session.driver', 'array');
    $config->set('logging.default', 'null');
});
$app->booting(function (Application $app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
    Mail::fake();
});
$app->make(Kernel::class)->bootstrap();

$target = DB::selectOne(<<<'SQL'
    SELECT current_database() AS database, current_user AS owner,
        shobj_description(oid, 'pg_database') AS marker
    FROM pg_database WHERE datname = current_database()
    SQL);
if ($target->database !== 'psikotes_organization_test' || $target->owner !== 'org_test_owner'
    || $target->marker !== 'ONCAM_ORG_TEST:'.$runId) {
    throw new RuntimeException('Disposable database identity mismatch; migrations refused.');
}
if (DB::selectOne("SELECT to_regclass('public.migrations') AS name")->name !== null) {
    throw new RuntimeException('Runner requires a fresh disposable database; existing schema refused.');
}

if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Disposable database migration failed.');
}
DB::statement('ALTER ROLE psikotes_runtime LOGIN');
config()->set('database.connections.pgsql.username', 'psikotes_runtime');
DB::purge('pgsql');
if (DB::selectOne('SELECT current_user AS name')->name !== 'psikotes_runtime') {
    throw new RuntimeException('Tests must connect as runtime, not migration owner.');
}
