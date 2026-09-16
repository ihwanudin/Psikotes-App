<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Entitlement;
use App\Models\Order;
use App\Services\Payments\ReconcilePendingXenditPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class XenditStatusReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
        config()->set('services.xendit.secret_key', 'xnd_test_secret');
        Http::preventStrayRequests();
    }

    public function test_pending_status_check_does_not_block_later_paid_reconciliation(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        Http::fake([
            'https://api.xendit.co/v2/invoices/invoice-123' => Http::sequence()
                ->push($this->invoicePayload('PENDING'))
                ->push($this->invoicePayload('PAID', '2026-08-25T13:05:00+07:00')),
        ]);
        $reconcile = app(ReconcilePendingXenditPayments::class);

        $pending = $reconcile->handle();
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'locked');

        $paid = $reconcile->handle();

        $this->assertSame(1, $pending->checked);
        $this->assertSame(0, $pending->applied);
        $this->assertSame(1, $paid->checked);
        $this->assertSame(1, $paid->applied);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'ready');
        $this->assertDatabaseCount('payment_webhook_events', 2);
    }

    public function test_reconciliation_command_is_safe_when_there_are_no_pending_invoices(): void
    {
        $command = $this->artisan('payments:reconcile-xendit');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('Checked 0; applied 0; failed 0.')->assertSuccessful();
    }

    public function test_reconciliation_command_rejects_an_unbounded_limit(): void
    {
        $command = $this->artisan('payments:reconcile-xendit', ['--limit' => 501]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command
            ->expectsOutput('The limit must be an integer between 1 and 500.')
            ->assertExitCode(2);
    }

    /** @return array{Order, Entitlement} */
    private function orderWithLockedEntitlement(): array
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'xendit',
            amount: 350_000,
            gatewayReference: 'invoice-123',
            expiresAt: Date::now()->addHour(),
        );
        $fixture['order']->forceFill(['invoice_url' => 'https://invoice.xendit.co/invoice-123'])->save();

        return [$fixture['order'], $fixture['entitlements']['ist']];
    }

    /** @return array<string, mixed> */
    private function invoicePayload(string $status, ?string $paidAt = null): array
    {
        return [
            'id' => 'invoice-123',
            'external_id' => Order::query()->sole()->public_id,
            'status' => $status,
            'amount' => 350_000,
            'currency' => 'IDR',
            'invoice_url' => 'https://invoice.xendit.co/invoice-123',
            'expiry_date' => '2026-08-25T14:00:00+07:00',
            'paid_at' => $paidAt,
            'created' => '2026-08-25T13:00:00+07:00',
            'updated' => $paidAt ?? '2026-08-25T13:00:00+07:00',
        ];
    }

    private function assertEntitlements(Order $order, string $status): void
    {
        $this->assertSame(
            ['dass21' => $status, 'ist' => $status],
            Entitlement::query()->where('order_id', $order->id)->orderBy('test_type')->pluck('status', 'test_type')->all(),
        );
    }
}
