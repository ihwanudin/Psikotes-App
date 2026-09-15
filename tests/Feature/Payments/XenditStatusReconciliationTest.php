<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Services\Payments\ReconcilePendingXenditPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Support\DirectPublicPaymentFixture;
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
        $paid = $reconcile->handle();

        $this->assertSame(1, $pending->checked);
        $this->assertSame(0, $pending->applied);
        $this->assertSame(1, $paid->checked);
        $this->assertSame(1, $paid->applied);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('ready', $entitlement->fresh()->status);
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
        $branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'test_number' => 'LSI-202608-000001-ABCDEF',
        ]);
        $orderPublicId = (string) Str::ulid();
        $case = DirectPublicPaymentFixture::caseFor($participant, $branch, $orderPublicId, 350_000);
        $methodId = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit',
            'display_name' => 'Xendit Invoice',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $methodId,
            'status' => 'pending',
            'amount' => 350_000,
            'currency' => 'IDR',
            'gateway_ref' => 'invoice-123',
            'invoice_url' => 'https://invoice.xendit.co/invoice-123',
            'expires_at' => Date::now()->addHour(),
        ]);
        $entitlement = Entitlement::query()->create([
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'order_id' => $order->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);

        return [$order, $entitlement];
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
}
