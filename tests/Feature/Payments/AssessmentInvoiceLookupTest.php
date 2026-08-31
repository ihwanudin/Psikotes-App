<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Services\Payments\Exceptions\PaymentProviderException;
use App\Services\Payments\FakePaymentProvider;
use App\Services\Payments\XenditProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

final class AssessmentInvoiceLookupTest extends OrganizationPaymentTestCase
{
    private const string REFERENCE = 'AB_01K3H9M5YXB62D9QK7E5V2G8Z1';

    private const string UNKNOWN = 'Invoice lookup outcome is unknown.';

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-01T00:00:00Z');
        config()->set('services.xendit', [
            'secret_key' => 'synthetic-lookup-secret', 'base_url' => 'https://api.xendit.co',
            'connect_timeout_seconds' => 3, 'timeout_seconds' => 10,
        ]);
        Http::preventStrayRequests();
        Http::fake([]);
    }

    #[DataProvider('invoiceStates')]
    public function test_xendit_recovers_exact_invoice_with_one_read_only_request(string $status, string $expiry): void
    {
        Http::fake(['https://api.xendit.co/v2/invoices*' => Http::response([
            $this->payload(['status' => $status, 'expiry_date' => $expiry]),
        ])]);
        $provider = new XenditProvider;
        $this->assertInstanceOf(PaymentProvider::class, $provider);
        $invoice = $provider->lookupInvoice(self::REFERENCE, 350_000, 'IDR');

        $this->assertInstanceOf(PaymentInvoice::class, $invoice);
        $this->assertSame('invoice-123', $invoice->providerReference);
        $this->assertSame('https://invoice.xendit.co/invoice-123', $invoice->paymentUrl);
        $this->assertSame(350_000, $invoice->amount);
        $this->assertSame('IDR', $invoice->currency);
        $this->assertTrue($invoice->expiresAt->equalTo($expiry));
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/v2/invoices'
                && $query === ['external_id' => self::REFERENCE, 'limit' => '2']
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('synthetic-lookup-secret:'))
                && $request->body() === '';
        });
    }

    public static function invoiceStates(): iterable
    {
        yield ['PENDING', '2026-09-01T01:00:00Z'];
        yield ['PAID', '2026-08-31T01:00:00+07:00'];
        yield ['SETTLED', '2026-08-31T01:00:00.123Z'];
        yield ['EXPIRED', '2026-08-31T01:00:00.123456+00:00'];
    }

    #[DataProvider('invalidInputs')]
    public function test_both_providers_reject_invalid_input_before_http(string $reference, int $amount, string $currency): void
    {
        foreach ([new XenditProvider, new FakePaymentProvider] as $provider) {
            try {
                $provider->lookupInvoice($reference, $amount, $currency);
                $this->fail('Invalid lookup must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invoice lookup input is invalid.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public static function invalidInputs(): iterable
    {
        yield [self::REFERENCE, 0, 'IDR'];
        yield [self::REFERENCE, -1, 'IDR'];
        yield [self::REFERENCE, 1, 'USD'];
        yield [self::REFERENCE, 1, 'idr'];
        yield ['', 1, 'IDR'];
        yield ['AB_bad?external_id=other', 1, 'IDR'];
        yield ["AB_bad\n", 1, 'IDR'];
        yield [str_repeat('A', 65), 1, 'IDR'];
    }

    #[DataProvider('invalidResponses')]
    public function test_unknown_provider_responses_never_authorize_post_or_leak_payload(string $scenario): void
    {
        $valid = $this->payload();
        $body = match ($scenario) {
            'empty' => [],
            'object' => $valid,
            'json-object-list' => '{"0":'.json_encode($valid, JSON_THROW_ON_ERROR).'}',
            'malformed-json' => 'synthetic-private@example.test not JSON',
            'null' => null,
            'scalar' => [true],
            'duplicate' => [$valid, [...$valid, 'id' => 'invoice-456']],
            'duplicate-different-amount' => [$valid, [...$valid, 'id' => 'invoice-456', 'amount' => 1]],
            'foreign-plus-match' => [[...$valid, 'external_id' => 'AB_foreign'], $valid],
            'foreign-reference' => [[...$valid, 'external_id' => 'AB_foreign']],
            'wrong-amount' => [[...$valid, 'amount' => 350_001]],
            'string-amount' => [[...$valid, 'amount' => '350000']],
            'float-amount' => str_replace('350000', '350000.0', json_encode([$valid], JSON_THROW_ON_ERROR)),
            'zero-amount' => [[...$valid, 'amount' => 0]],
            'wrong-currency' => [[...$valid, 'currency' => 'USD']],
            'missing-id' => [array_diff_key($valid, ['id' => true])],
            'invalid-id' => [[...$valid, 'id' => 'synthetic private@example.test']],
            'id-newline' => [[...$valid, 'id' => "invoice-123\n"]],
            'missing-expiry' => [array_diff_key($valid, ['expiry_date' => true])],
            'relative-expiry' => [[...$valid, 'expiry_date' => 'tomorrow']],
            'named-zone-expiry' => [[...$valid, 'expiry_date' => '2026-09-01T01:00:00UTC']],
            'invalid-offset-expiry' => [[...$valid, 'expiry_date' => '2026-09-01T01:00:00+99:99']],
            'invalid-calendar-expiry' => [[...$valid, 'expiry_date' => '2026-02-30T00:00:00Z']],
            'missing-status' => [array_diff_key($valid, ['status' => true])],
            'unknown-status' => [[...$valid, 'status' => 'synthetic-secret']],
            'wrong-url' => [[...$valid, 'invoice_url' => 'https://xendit.co.attacker.test/pay']],
            'insecure-url' => [[...$valid, 'invoice_url' => 'http://invoice.xendit.co/pay']],
            'url-userinfo' => [[...$valid, 'invoice_url' => 'https://private:secret@invoice.xendit.co/pay']],
            default => [$valid],
        };
        $status = match ($scenario) {
            'redirect' => 302, 'unauthorized' => 401, 'not-found' => 404,
            'rate-limited' => 429, 'server-error' => 500, default => 200,
        };
        Http::fake(['https://api.xendit.co/v2/invoices*' => Http::response($body, $status,
            ['Location' => 'https://attacker.example.test/secret'])]);

        $this->assertUnknown(new XenditProvider);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public static function invalidResponses(): iterable
    {
        foreach (['empty', 'object', 'json-object-list', 'malformed-json', 'null', 'scalar',
            'duplicate', 'duplicate-different-amount', 'foreign-plus-match', 'foreign-reference',
            'wrong-amount', 'string-amount', 'float-amount', 'zero-amount', 'wrong-currency',
            'missing-id', 'invalid-id', 'id-newline', 'missing-expiry', 'relative-expiry', 'named-zone-expiry', 'invalid-offset-expiry', 'invalid-calendar-expiry',
            'missing-status', 'unknown-status', 'wrong-url', 'insecure-url', 'url-userinfo',
            'redirect', 'unauthorized', 'not-found', 'rate-limited', 'server-error'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    public function test_timeout_is_sanitized_unknown_without_retry_or_mutation(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            $this->assertSame('GET', $request->method());

            return Http::failedConnection('synthetic-secret private@example.test');
        });
        $this->assertUnknown(new XenditProvider);
        $this->assertSame(1, $calls);
    }

    public function test_lookup_keeps_existing_timeout_and_redirect_guards(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response([$this->payload()]);
        });
        $this->assertSame('invoice-123', (new XenditProvider)->lookupInvoice(self::REFERENCE, 350_000, 'IDR')->providerReference);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_provider_does_not_send_a_request(): void
    {
        config()->set('services.xendit.secret_key', '');
        $this->assertUnknown(new XenditProvider);
        Http::assertNothingSent();
    }

    #[DataProvider('fakeStates')]
    public function test_fake_lookup_preserves_stored_invoice_and_status(string $state): void
    {
        $provider = new FakePaymentProvider;
        $created = $provider->createInvoice(new CreateInvoiceRequest(self::REFERENCE, 350_000, 'IDR',
            'Synthetic invoice', Date::now()->addHour()));
        if ($state === 'paid') {
            $provider->markPaid($created->providerReference, 'synthetic-paid');
        } elseif ($state === 'expired') {
            $provider->expireInvoice($created->providerReference);
        }
        Date::setTestNow('2026-09-02T00:00:00Z');
        $before = serialize($provider);
        $this->assertSame($created, $provider->lookupInvoice(self::REFERENCE, 350_000, 'IDR'));
        $this->assertUnknown($provider, amount: 350_001);
        $this->assertUnknown($provider, reference: 'AB_other');
        $this->assertSame($before, serialize($provider));
        Http::assertNothingSent();
    }

    public static function fakeStates(): iterable
    {
        yield ['pending'];
        yield ['paid'];
        yield ['expired'];
    }

    public function test_empty_fake_remains_unknown_and_does_not_create_a_record(): void
    {
        $provider = new FakePaymentProvider;
        $before = serialize($provider);
        $this->assertUnknown($provider);
        $this->assertUnknown($provider);
        $this->assertSame($before, serialize($provider));
        Http::assertNothingSent();
    }

    private function assertUnknown(PaymentProvider $provider, string $reference = self::REFERENCE, int $amount = 350_000): void
    {
        try {
            $provider->lookupInvoice($reference, $amount, 'IDR');
            $this->fail('An unresolved invoice must remain unknown, never permission to create again.');
        } catch (PaymentProviderException $exception) {
            $this->assertSame(self::UNKNOWN, $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'Do not attach provider payload or credentials through a previous exception.');
        }
    }

    private function payload(array $overrides = []): array
    {
        return [...[
            'id' => 'invoice-123', 'external_id' => self::REFERENCE, 'amount' => 350_000,
            'currency' => 'IDR', 'status' => 'PENDING',
            'invoice_url' => 'https://invoice.xendit.co/invoice-123',
            'expiry_date' => '2026-09-01T01:00:00Z',
        ], ...$overrides];
    }
}
