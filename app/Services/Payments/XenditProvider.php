<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\PaymentWebhookInput;
use App\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\PaymentProviderException;
use App\Services\Payments\Exceptions\WebhookAuthenticationFailed;
use App\Services\Payments\Exceptions\WebhookPayloadRejected;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;

final class XenditProvider implements PaymentProvider
{
    public function lookupInvoice(string $merchantReference, int $amount, string $currency): PaymentInvoice
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $merchantReference) || $amount < 1 || $currency !== 'IDR') {
            throw new InvalidArgumentException('Invoice lookup input is invalid.');
        }

        try {
            // No status/date filters: they could conceal another invoice for the same intent.
            $response = $this->request()->get('/v2/invoices', ['external_id' => $merchantReference, 'limit' => 2]);
            if (! $response->ok()) {
                throw new PaymentProviderException('Invoice lookup outcome is unknown.');
            }

            // Preserve JSON array/object types; associative decoding accepts {"0": ...} as a list.
            $payload = json_decode($response->body(), false, 16, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || count($payload) !== 1 || ! $payload[0] instanceof stdClass) {
                throw new PaymentProviderException('Invoice lookup outcome is unknown.');
            }

            $candidate = $payload[0];
            if (($candidate->external_id ?? null) !== $merchantReference
                || ($candidate->amount ?? null) !== $amount || ($candidate->currency ?? null) !== $currency
                || ! in_array($candidate->status ?? null, ['PENDING', 'PAID', 'SETTLED', 'EXPIRED'], true)
                || ! is_string($candidate->id ?? null) || ! is_string($candidate->invoice_url ?? null)
                || ! is_string($candidate->expiry_date ?? null)
                || ! preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $candidate->id)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $candidate->expiry_date)) {
                throw new PaymentProviderException('Invoice lookup outcome is unknown.');
            }

            $url = parse_url($candidate->invoice_url);
            $host = is_array($url) ? ($url['host'] ?? null) : null;
            if (! is_string($host) || ($host !== 'xendit.co' && ! str_ends_with($host, '.xendit.co'))
                || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
                throw new PaymentProviderException('Invoice lookup outcome is unknown.');
            }

            $rawExpiry = $candidate->expiry_date;
            $format = str_contains($rawExpiry, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
            $expiry = CarbonImmutable::createFromFormat($format, $rawExpiry);
            $errors = CarbonImmutable::getLastErrors();
            if ($expiry === null || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new PaymentProviderException('Invoice lookup outcome is unknown.');
            }

            return new PaymentInvoice($candidate->id, $candidate->invoice_url, $amount, $currency, $expiry);
        } catch (ConnectionException|JsonException|InvalidArgumentException|PaymentProviderException) {
            // Do not attach transport exceptions: their URL/body can contain private provider data.
            throw new PaymentProviderException('Invoice lookup outcome is unknown.');
        }
    }

    public function createInvoice(CreateInvoiceRequest $request): PaymentInvoice
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->request()->post('/v2/invoices', [
                'external_id' => $request->orderReference,
                'amount' => $request->amount,
                'description' => $request->description,
                'invoice_duration' => (int) Date::now()->diffInSeconds($request->expiresAt),
                'currency' => $request->currency,
            ]);

            if ($response->successful()) {
                $invoice = $this->invoiceFromPayload($this->objectPayload($response), $request);
                $this->logRequest('create_invoice', 'succeeded', $startedAt, $response);

                return $invoice;
            }

            $this->logRequest('create_invoice', 'unknown', $startedAt, $response);
        } catch (ConnectionException) {
            $this->logRequest('create_invoice', 'unknown', $startedAt);
        }

        return $this->findInvoiceByExternalId($request);
    }

    public function checkStatus(string $providerReference): PaymentEvent
    {
        try {
            $response = $this->request()->get('/v2/invoices/'.$this->safeReference($providerReference));
        } catch (ConnectionException) {
            throw new PaymentProviderException('Xendit invoice status is unavailable.');
        }

        if (! $response->successful()) {
            throw new PaymentProviderException('Xendit invoice status is unavailable.');
        }

        return $this->eventFromPayload($this->objectPayload($response), $providerReference);
    }

    public function normalizeWebhook(PaymentWebhookInput $input): PaymentEvent
    {
        $configuredToken = config('services.xendit.callback_token');
        $providedToken = $input->header('x-callback-token');

        if (! is_string($configuredToken)
            || $configuredToken === ''
            || ! is_string($providedToken)
            || ! hash_equals($configuredToken, $providedToken)) {
            throw new WebhookAuthenticationFailed('Xendit webhook was rejected.');
        }

        try {
            return $this->eventFromPayload($input->payload);
        } catch (Throwable $exception) {
            throw new WebhookPayloadRejected('Xendit webhook was rejected.', previous: $exception);
        }
    }

    public function expireInvoice(string $providerReference): PaymentEvent
    {
        $reference = $this->safeReference($providerReference);
        $response = $this->request()->post("/invoices/{$reference}/expire!");

        if (! $response->successful()) {
            throw new PaymentProviderException('Xendit invoice could not be expired.');
        }

        return $this->eventFromPayload($this->objectPayload($response), $reference);
    }

    private function findInvoiceByExternalId(CreateInvoiceRequest $request): PaymentInvoice
    {
        try {
            $response = $this->request()->get('/v2/invoices', [
                'external_id' => $request->orderReference,
            ]);
        } catch (ConnectionException $exception) {
            throw new PaymentProviderException('Xendit invoice outcome is unknown.', previous: $exception);
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload) || ! array_is_list($payload)) {
            throw new PaymentProviderException('Xendit invoice outcome is unknown.');
        }

        foreach ($payload as $candidate) {
            if (is_array($candidate) && ($candidate['external_id'] ?? null) === $request->orderReference) {
                return $this->invoiceFromPayload($candidate, $request);
            }
        }

        throw new PaymentProviderException('Xendit invoice outcome is unknown.');
    }

    /** @param array<string, mixed> $payload */
    private function invoiceFromPayload(array $payload, CreateInvoiceRequest $request): PaymentInvoice
    {
        $providerReference = $payload['id'] ?? null;
        $merchantReference = $payload['external_id'] ?? null;
        $paymentUrl = $payload['invoice_url'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;
        $rawExpiresAt = $payload['expiry_date'] ?? null;

        if (! is_string($providerReference)
            || ! is_string($merchantReference)
            || ! is_string($paymentUrl)
            || ! is_int($amount)
            || ! is_string($currency)
            || ! is_string($rawExpiresAt)
            || $merchantReference !== $request->orderReference
            || $amount !== $request->amount
            || $currency !== $request->currency) {
            throw new PaymentProviderException('Xendit returned an invalid invoice.');
        }

        try {
            $expiresAt = CarbonImmutable::parse($rawExpiresAt);
            $host = parse_url($paymentUrl, PHP_URL_HOST);

            if (! is_string($host)
                || ($host !== 'xendit.co' && ! str_ends_with($host, '.xendit.co'))) {
                throw new PaymentProviderException('Xendit returned an invalid invoice.');
            }

            return new PaymentInvoice($providerReference, $paymentUrl, $amount, $currency, $expiresAt);
        } catch (Throwable $exception) {
            throw new PaymentProviderException('Xendit returned an invalid invoice.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function eventFromPayload(array $payload, ?string $expectedProviderReference = null): PaymentEvent
    {
        $providerReference = $payload['id'] ?? null;
        $merchantReference = $payload['external_id'] ?? null;
        $rawStatus = $payload['status'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;

        if (! is_string($providerReference)
            || ! is_string($merchantReference)
            || ! is_string($rawStatus)
            || ! is_int($amount)
            || ! is_string($currency)
            || ($expectedProviderReference !== null && $providerReference !== $expectedProviderReference)) {
            throw new PaymentProviderException('Xendit returned an invalid payment event.');
        }

        $status = match ($rawStatus) {
            'PENDING' => PaymentStatus::Pending,
            'PAID', 'SETTLED' => PaymentStatus::Paid,
            'EXPIRED' => PaymentStatus::Expired,
            default => throw new PaymentProviderException('Xendit returned an invalid payment event.'),
        };
        $rawOccurredAt = $status === PaymentStatus::Paid
            ? ($payload['paid_at'] ?? $payload['updated'] ?? null)
            : ($payload['updated'] ?? $payload['created'] ?? null);

        if (! is_string($rawOccurredAt)) {
            throw new PaymentProviderException('Xendit returned an invalid payment event.');
        }

        try {
            return new PaymentEvent(
                eventId: $this->eventId($providerReference, $status),
                providerReference: $providerReference,
                merchantReference: $merchantReference,
                status: $status,
                occurredAt: CarbonImmutable::parse($rawOccurredAt),
                amount: $amount,
                currency: $currency,
            );
        } catch (Throwable $exception) {
            throw new PaymentProviderException('Xendit returned an invalid payment event.', previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        $secretKey = config('services.xendit.secret_key');
        $baseUrl = config('services.xendit.base_url');

        if (! is_string($secretKey) || $secretKey === '' || $baseUrl !== 'https://api.xendit.co') {
            throw new PaymentProviderException('Xendit is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withBasicAuth($secretKey, '')
            ->baseUrl($baseUrl)
            ->connectTimeout((int) config('services.xendit.connect_timeout_seconds', 3))
            ->timeout((int) config('services.xendit.timeout_seconds', 10))
            ->withoutRedirecting();
    }

    /** @return array<string, mixed> */
    private function objectPayload(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload) || array_is_list($payload)) {
            throw new PaymentProviderException('Xendit returned an invalid response.');
        }

        return $payload;
    }

    private function safeReference(string $reference): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,160}$/', $reference)) {
            throw new PaymentProviderException('Xendit invoice reference is invalid.');
        }

        return $reference;
    }

    private function eventId(string $providerReference, PaymentStatus $status): string
    {
        return 'xendit-invoice:'.hash('sha256', $providerReference).':'.$status->value;
    }

    private function logRequest(
        string $operation,
        string $outcome,
        int $startedAt,
        ?Response $response = null,
    ): void {
        Log::info('xendit_api_request', [
            'operation' => $operation,
            'outcome' => $outcome,
            'status_class' => $response === null ? null : intdiv($response->status(), 100).'xx',
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'provider_request_id' => $response?->header('Request-ID'),
        ]);
    }
}
