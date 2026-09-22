<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

final class CentralAdminPaymentMethodsRlsTest extends TestCase
{
    private static int $paymentMethodId;

    public static function setUpBeforeClass(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            self::$paymentMethodId = DB::table('payment_methods')->insertGetId([
                'code' => 'central_admin_test_method',
                'display_name' => 'Central Admin Test Method',
                'is_active' => false,
            ]);
        });
    }

    public function test_central_admin_can_read_payment_methods(): void
    {
        $runner = app(RlsContextRunner::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            $methods = DB::table('payment_methods')->pluck('id')->all();

            $this->assertContains(self::$paymentMethodId, $methods);
        });
    }

    public function test_central_admin_cannot_insert_payment_methods(): void
    {
        $runner = app(RlsContextRunner::class);

        $this->expectException(QueryException::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            DB::table('payment_methods')->insert([
                'code' => 'central_admin_illegal_insert',
                'display_name' => 'Should not be allowed',
                'is_active' => false,
            ]);
        });
    }

    public function test_central_admin_cannot_update_payment_methods(): void
    {
        $runner = app(RlsContextRunner::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            $updated = DB::table('payment_methods')
                ->where('id', self::$paymentMethodId)
                ->update(['display_name' => 'Tampered by central_admin']);

            $this->assertSame(0, $updated);
        });

        // Verify the name is unchanged
        $runner->runAsService(function (): void {
            $name = DB::table('payment_methods')
                ->where('id', self::$paymentMethodId)
                ->value('display_name');

            $this->assertSame('Central Admin Test Method', $name);
        });
    }

    public function test_central_admin_cannot_delete_payment_methods(): void
    {
        $runner = app(RlsContextRunner::class);

        $runner->run(new RlsContext('central_admin'), function (): void {
            $deleted = DB::table('payment_methods')
                ->where('id', self::$paymentMethodId)
                ->delete();

            $this->assertSame(0, $deleted);
        });

        // Verify the row still exists
        $runner->runAsService(function (): void {
            $this->assertSame(
                1,
                DB::table('payment_methods')->where('id', self::$paymentMethodId)->count(),
            );
        });
    }
}
