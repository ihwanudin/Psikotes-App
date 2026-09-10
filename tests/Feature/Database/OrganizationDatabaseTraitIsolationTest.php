<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\OrganizationDatabaseLifecycleFixture as Fixture;

/** Each case starts a fresh process; the ordered fixture lifecycles share that process. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrganizationDatabaseTraitIsolationTest extends TestCase
{
    /** @param list<'truncate'|'refresh'> $sequence */
    #[DataProvider('orderedTraits')]
    public function test_ordered_lifecycles_keep_schema_and_isolate_rows(array $sequence): void
    {
        $previousPdo = null;
        $previousTrait = null;
        foreach ($sequence as $index => $trait) {
            $fixture = $trait === 'truncate' ? Fixture::truncating() : Fixture::refreshing();
            if ($previousTrait === 'refresh' && $trait === 'truncate') {
                $this->assertTrue(RefreshDatabaseState::$migrated);
            }
            try {
                $fixture->startLifecycle();
                $this->assertSame('sqlite', config('database.default'));
                $this->assertSame(':memory:', DB::connection()->getDatabaseName());
                $this->assertTrue(Schema::hasTable('branches'));
                $this->assertSame(0, DB::table('branches')->count());
                $this->assertSame($trait === 'truncate' ? 0 : 1, DB::transactionLevel());
                $pdo = DB::connection()->getPdo();
                if ($trait === 'refresh' && $previousTrait === 'refresh') {
                    $this->assertSame($previousPdo, $pdo, 'Normal RefreshDatabase must retain its cached PDO.');
                }
                if ($trait === 'truncate' && $previousPdo !== null) {
                    $this->assertNotSame($previousPdo, $pdo);
                }
                DB::table('branches')->insert(['code' => 'lifecycle-'.$index,
                    'ref_code' => 'lifecycle-'.$index, 'name' => 'Synthetic lifecycle']);
                $previousPdo = $pdo;
                $previousTrait = $trait;
            } finally {
                $fixture->finishLifecycle();
            }
            $this->assertTrue($fixture->applicationDestroyed());
            $this->assertSame($trait === 'refresh', RefreshDatabaseState::$migrated);
        }
    }

    /** @return iterable<string, array{list<'truncate'|'refresh'>}> */
    public static function orderedTraits(): iterable
    {
        yield 'truncate then refresh' => [['truncate', 'refresh']];
        yield 'refresh truncate refresh' => [['refresh', 'truncate', 'refresh']];
        yield 'repeated truncation then refresh' => [['truncate', 'truncate', 'refresh']];
        yield 'normal refresh caching' => [['refresh', 'refresh']];
    }

    public function test_parent_teardown_preserves_first_exception_and_callbacks_then_next_refresh_works(): void
    {
        $fixture = Fixture::truncating();
        $fixture->startLifecycle();
        $first = new RuntimeException('Synthetic first teardown failure');
        $second = new RuntimeException('Synthetic later teardown failure');
        $callbacks = [];
        $fixture->onDestroy(function () use (&$callbacks, $first): void {
            $callbacks[] = 'first';
            throw $first;
        });
        $fixture->onDestroy(function () use (&$callbacks, $second): void {
            $callbacks[] = 'second';
            throw $second;
        });
        $fixture->onDestroy(function () use (&$callbacks): void {
            $callbacks[] = 'last';
        });
        try {
            $fixture->finishLifecycle();
            $this->fail('The original parent teardown exception must propagate.');
        } catch (RuntimeException $caught) {
            $this->assertSame($first, $caught);
        }
        $this->assertSame(['first', 'second', 'last'], $callbacks);
        $this->assertTrue($fixture->applicationDestroyed());
        $this->assertFalse(RefreshDatabaseState::$migrated);

        $next = Fixture::refreshing();
        try {
            $next->startLifecycle();
            $this->assertTrue(Schema::hasTable('branches'));
            $this->assertSame(0, DB::table('branches')->count());
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            $next->finishLifecycle();
        }
        $this->assertTrue(RefreshDatabaseState::$migrated);
    }
}
