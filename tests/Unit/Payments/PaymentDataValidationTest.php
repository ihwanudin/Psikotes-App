<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentDataValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidCreateRequests')]
    public function test_invalid_invoice_intent_is_rejected(array $overrides): void
    {
        $values = array_merge([
            'orderReference' => '01K3H9M5YXB62D9QK7E5V2G8Z1',
            'amount' => 350_000,
            'currency' => 'IDR',
            'description' => 'Paket IST',
            'expiresAt' => Date::now()->addHour(),
        ], $overrides);

        $this->expectException(InvalidArgumentException::class);

        new CreateInvoiceRequest(...$values);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidProviderOutputs')]
    public function test_provider_output_rejects_invalid_values(array $overrides): void
    {
        $values = array_merge([
            'providerReference' => 'provider-reference',
            'paymentUrl' => 'https://payments.example.test/invoice',
            'amount' => 350_000,
            'currency' => 'IDR',
            'expiresAt' => Date::now()->addHour(),
        ], $overrides);

        $this->expectException(InvalidArgumentException::class);

        new PaymentInvoice(...$values);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCreateRequests(): iterable
    {
        yield 'non IDR currency' => [['currency' => 'USD']];
        yield 'already expired' => [[
            'expiresAt' => CarbonImmutable::parse('2026-08-25 12:59:59+07:00'),
        ]];
        yield 'zero amount' => [['amount' => 0]];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidProviderOutputs(): iterable
    {
        yield 'non HTTPS URL' => [['paymentUrl' => 'http://payments.example.test/invoice']];
        yield 'non IDR currency' => [['currency' => 'USD']];
        yield 'zero amount' => [['amount' => 0]];
    }
}
