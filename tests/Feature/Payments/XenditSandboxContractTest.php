<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Data\Payments\CreateInvoiceRequest;
use App\Enums\PaymentStatus;
use App\Services\Payments\XenditProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('sandbox')]
final class XenditSandboxContractTest extends TestCase
{
    public function test_development_invoice_can_be_created_checked_and_expired(): void
    {
        $secretKey = getenv('XENDIT_SECRET_KEY');

        if (! is_string($secretKey) || ! str_starts_with($secretKey, 'xnd_development_')) {
            $this->markTestSkipped('Xendit development credential is not configured.');
        }

        config()->set('services.xendit.secret_key', $secretKey);
        Http::allowStrayRequests(['https://api.xendit.co/*']);
        $provider = app(XenditProvider::class);
        $reference = 'sandbox-'.Str::lower((string) Str::ulid());
        $invoice = $provider->createInvoice(new CreateInvoiceRequest(
            orderReference: $reference,
            amount: 10_000,
            currency: 'IDR',
            description: 'Psikotes LSI sandbox contract',
            expiresAt: Date::now()->addHour(),
        ));

        $this->assertSame(PaymentStatus::Pending, $provider->checkStatus($invoice->providerReference)->status);
        $this->assertSame(PaymentStatus::Expired, $provider->expireInvoice($invoice->providerReference)->status);
    }
}
