<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class OrganizationPaymentTestEnvironmentTest extends OrganizationPaymentTestCase
{
    public function test_only_an_in_memory_database_is_available(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));

        $this->expectException(InvalidArgumentException::class);
        DB::connection('pgsql');
    }

    public function test_payment_and_notification_adapters_are_fake_after_each_boot(): void
    {
        $this->assertInstanceOf(FakePaymentProvider::class, app(PaymentProvider::class));
        $this->assertInstanceOf(FakeNotifier::class, app(Notifier::class));
        $this->refreshApplication();
        $this->assertInstanceOf(FakePaymentProvider::class, app(PaymentProvider::class));
        $this->assertInstanceOf(FakeNotifier::class, app(Notifier::class));
    }

    public function test_unstubbed_http_cannot_leave_the_process(): void
    {
        $this->expectException(StrayRequestException::class);
        Http::post('https://payments.example.invalid/invoices');
    }

    public function test_explicit_http_fixtures_still_work(): void
    {
        Http::fake(['https://payments.example.invalid/*' => Http::response(['status' => 'pending'])]);
        $this->assertSame('pending', Http::get('https://payments.example.invalid/invoices')->json('status'));
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configuration_is_rejected_before_database_use(
        string $environment, string $connection, string $database, ?string $url,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Organization payment tests require testing with SQLite :memory: and no DB_URL.');
        self::assertSafeDatabase($environment, $connection, $database, $url);
    }

    /** @return array<string, array{string, string, string, string|null}> */
    public static function unsafeConfigurations(): array
    {
        return [
            'production' => ['production', 'sqlite', ':memory:', null],
            'local' => ['local', 'sqlite', ':memory:', null],
            'live database' => ['testing', 'pgsql', 'psikotes', null],
            'even a named test database requires a separate runner' => ['testing', 'pgsql', 'psikotes_test', null],
            'persistent sqlite' => ['testing', 'sqlite', 'database.sqlite', null],
            'url override' => ['testing', 'sqlite', ':memory:', 'postgres://example.invalid/psikotes'],
        ];
    }
}
