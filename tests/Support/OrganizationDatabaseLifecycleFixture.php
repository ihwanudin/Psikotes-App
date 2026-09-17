<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\OrganizationPaymentTestCase;

/** Exercises the real guarded framework lifecycle without a surrounding Laravel test app. */
abstract class OrganizationDatabaseLifecycleFixture extends OrganizationPaymentTestCase
{
    public static function truncating(): self
    {
        return new class('startLifecycle') extends OrganizationDatabaseLifecycleFixture
        {
            use DatabaseTruncation;

            protected function setUp(): void
            {
                // Match existing truncation users: fresh SQLite per application, no outer transaction.
                RefreshDatabaseState::$migrated = false;
                parent::setUp();
            }
        };
    }

    public static function refreshing(): self
    {
        return new class('startLifecycle') extends OrganizationDatabaseLifecycleFixture
        {
            use RefreshDatabase;
        };
    }

    public function startLifecycle(): void
    {
        $this->setUp();
    }

    public function finishLifecycle(): void
    {
        $this->tearDown();
    }

    public function onDestroy(Closure $callback): void
    {
        $this->beforeApplicationDestroyed($callback);
    }

    public function applicationDestroyed(): bool
    {
        return $this->app === null;
    }
}
