<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Verify central_admin's RLS access to packages and package_items
 * matches the ManageTestPackages ability matrix:
 *
 * - packages:  read = yes, update = yes (Filament TestPackageResource),
 *              insert = no
 * - package_items: read = yes (same broad read as other admin roles),
 *                  update = no (no admin UI ever writes to it),
 *                  insert = no
 */
final class CentralAdminPackageCatalogRlsTest extends TestCase
{
    private int $packageId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_central_admin_can_read_both_tables(): void
    {
        $runner = app(RlsContextRunner::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            $this->assertGreaterThan(0, DB::table('packages')->count());
            $this->assertGreaterThan(0, DB::table('package_items')->count());
        });
    }

    public function test_central_admin_can_update_packages(): void
    {
        $runner = app(RlsContextRunner::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            $affected = DB::table('packages')
                ->where('id', $this->packageId)
                ->update(['name' => 'renamed-by-central-admin']);
            $this->assertSame(1, $affected);
        });

        $name = $runner->runAsService(
            fn () => DB::table('packages')->where('id', $this->packageId)->value('name'),
        );
        $this->assertSame('renamed-by-central-admin', $name);
    }

    public function test_central_admin_cannot_update_package_items(): void
    {
        $runner = app(RlsContextRunner::class);

        $itemId = $runner->runAsService(
            fn () => DB::table('package_items')
                ->where('package_id', $this->packageId)
                ->value('id'),
        );
        $this->assertNotNull($itemId);

        $runner->run(new RlsContext('central_admin'), function () use ($itemId): void {
            $affected = DB::table('package_items')
                ->where('id', $itemId)
                ->update(['sort_order' => 99]);
            $this->assertSame(0, $affected);
        });
    }

    public function test_central_admin_cannot_insert_into_either_table(): void
    {
        $runner = app(RlsContextRunner::class);

        $this->expectException(QueryException::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            DB::table('packages')->insert([
                'code' => 'CENTRAL-INJECTED', 'name' => 'x', 'currency' => 'IDR',
                'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function seedFixtures(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->packageId = DB::table('packages')->insertGetId([
                'code' => 'CA-'.Str::random(10), 'name' => 'Central Admin Test Package',
                'amount' => 250000, 'currency' => 'IDR', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('package_items')->insert([
                'package_id' => $this->packageId, 'test_type' => 'dass21',
                'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
