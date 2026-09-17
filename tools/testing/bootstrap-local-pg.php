<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';

// Ensure storage directories exist
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $directory) {
    $path = $app->storagePath($directory);
    if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
        throw new RuntimeException('Unable to initialize storage.');
    }
}

$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $config = $app->make('config');
    $config->set('database.default', 'pgsql');
    $config->set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 5432),
        'database' => env('DB_DATABASE', 'psikotes'),
        'username' => env('DB_RUNTIME_USERNAME', 'psikotes_runtime'),
        'password' => env('DB_RUNTIME_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'disable',
    ]);
    $config->set('database.connections.pgsql_migration', [
        'driver' => 'pgsql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 5432),
        'database' => env('DB_DATABASE', 'psikotes'),
        'username' => env('DB_OWNER_USERNAME', 'psikotes_owner'),
        'password' => env('DB_OWNER_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'disable',
    ]);
    $config->set('database.redis', []);
    $config->set('cache.default', 'array');
    $config->set('queue.default', 'sync');
    $config->set('session.driver', 'array');
    $config->set('logging.default', 'null');
});

$app->make(Kernel::class)->bootstrap();

$currentUser = DB::selectOne('SELECT current_user AS name')->name;
if ($currentUser !== 'psikotes_runtime') {
    throw new RuntimeException("Tests must connect as psikotes_runtime, got: {$currentUser}");
}
