<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

abstract class OrganizationPaymentTestCase extends TestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset(class_uses_recursive(static::class)[DatabaseTruncation::class])) {
                // This guarded base uses SQLite memory: truncation migrated a PDO it does not cache.
                RefreshDatabaseState::$migrated = false;
            }
        }
    }

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        // Guard before providers boot and before RefreshDatabase can migrate.
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app->make('config');
            $connection = $config->get('database.default');
            self::assertSafeDatabase(
                $config->get('app.env'),
                $connection,
                $config->get("database.connections.{$connection}.database"),
                $config->get("database.connections.{$connection}.url"),
            );

            // Do not silently map explicit pgsql queries to SQLite: fail closed.
            $config->set('database.connections', ['sqlite' => $config->get('database.connections.sqlite')]);
            $config->set('database.redis', []);
            $config->set('cache.default', 'array');
            $config->set('session.driver', 'array');
            $config->set('queue.default', 'sync');
            $config->set('mail.default', 'array');
        });

        $app->booting(function (Application $app): void {
            $app->instance(PaymentProvider::class, new FakePaymentProvider);
            $app->instance(Notifier::class, new FakeNotifier);
            Http::preventStrayRequests();
            Mail::fake();
            Storage::fake('local');
            Storage::fake('s3');
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public static function assertSafeDatabase(
        mixed $environment,
        mixed $connection,
        mixed $database,
        mixed $url,
    ): void {
        if ($environment !== 'testing' || $connection !== 'sqlite'
            || $database !== ':memory:' || ! in_array($url, [null, ''], true)) {
            throw new RuntimeException(
                'Organization payment tests require testing with SQLite :memory: and no DB_URL.',
            );
        }
    }
}
