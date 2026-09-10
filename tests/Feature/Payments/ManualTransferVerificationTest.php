<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\VerifyManualTransfer;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Services\Payments\Exceptions\InvalidOrderTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class ManualTransferVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 10:00:00+07:00');
    }

    public function test_authorized_branch_admin_approves_once_and_unlocks_entitlements_atomically(): void
    {
        [$branch, $order, $entitlement] = $this->manualOrder();
        $admin = $this->admin($branch, canVerify: true);
        $action = app(VerifyManualTransfer::class);

        $action->approve($admin, $order->id, (string) $order->proof_object_key);

        $order->refresh();
        $entitlement->refresh();
        $this->assertSame('paid', $order->status->value);
        $this->assertSame($admin->id, $order->verified_by_admin_id);
        $this->assertTrue($order->verified_at?->equalTo(Date::now()) ?? false);
        $this->assertTrue($order->paid_at?->equalTo(Date::now()) ?? false);
        $this->assertSame('ready', $entitlement->status);
        $this->assertTrue($entitlement->ready_at?->equalTo(Date::now()) ?? false);
        $this->assertEntitlements($order, 'ready');
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'actor_id' => (string) $admin->id,
            'action' => 'manual_transfer.approved',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->public_id,
        ]);

        $paidAt = $order->paid_at;
        $readyAt = $entitlement->ready_at;
        Date::setTestNow('2026-08-25 11:00:00+07:00');
        $action->approve($admin, $order->id, (string) $order->proof_object_key);

        $this->assertTrue($order->fresh()->paid_at?->equalTo($paidAt) ?? false);
        $this->assertTrue($entitlement->fresh()->ready_at?->equalTo($readyAt) ?? false);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'participant.activation',
            'aggregate_type' => Order::class,
            'aggregate_id' => $order->public_id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_reject_stores_the_trimmed_reason_once_and_keeps_entitlement_locked(): void
    {
        [$branch, $order, $entitlement] = $this->manualOrder();
        $admin = $this->admin($branch, canVerify: true);
        $action = app(VerifyManualTransfer::class);

        $action->reject($admin, $order->id, (string) $order->proof_object_key, '  Nominal pada bukti tidak sesuai.  ');

        $order->refresh();
        $this->assertSame('rejected', $order->status->value);
        $this->assertSame('Nominal pada bukti tidak sesuai.', $order->rejection_reason);
        $this->assertSame($admin->id, $order->verified_by_admin_id);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertNull($entitlement->fresh()->ready_at);
        $this->assertEntitlements($order, 'locked');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'manual_transfer.rejected',
            'subject_id' => (string) $order->public_id,
        ]);

        $action->reject($admin, $order->id, (string) $order->proof_object_key, 'Alasan pengganti tidak boleh menimpa.');
        $this->assertSame('Nominal pada bukti tidak sesuai.', $order->fresh()->rejection_reason);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_opposite_decision_after_a_terminal_review_is_rejected(): void
    {
        [$branch, $order] = $this->manualOrder();
        $admin = $this->admin($branch, canVerify: true);
        $action = app(VerifyManualTransfer::class);
        $action->reject($admin, $order->id, (string) $order->proof_object_key, 'Bukti tidak dapat dibaca.');

        $this->expectException(InvalidOrderTransition::class);

        $action->approve($admin, $order->id, (string) $order->proof_object_key);
    }

    public function test_review_fails_closed_when_the_proof_changed_after_admin_loaded_it(): void
    {
        [$branch, $order, $entitlement] = $this->manualOrder();
        $admin = $this->admin($branch, canVerify: true);
        $reviewedProofKey = (string) $order->proof_object_key;

        $order->forceFill(['proof_object_key' => 'manual/replacement-proof.jpg'])->save();

        try {
            app(VerifyManualTransfer::class)->approve($admin, $order->id, $reviewedProofKey);
            $this->fail('A decision must not apply to a proof that was replaced after review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_proof', $exception->errors());
        }

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'locked');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_without_ability_and_cross_branch_admin_cannot_verify(): void
    {
        [$branchA, $order, $entitlement] = $this->manualOrder();
        [$branchB] = $this->manualOrder();
        $action = app(VerifyManualTransfer::class);

        foreach ([
            $this->admin($branchA, canVerify: false),
            $this->admin($branchB, canVerify: true),
        ] as $admin) {
            try {
                $action->approve($admin, $order->id, (string) $order->proof_object_key);
                $this->fail('Unauthorized verification should throw.');
            } catch (AuthorizationException) {
                $this->assertSame('pending', $order->fresh()->status->value);
                $this->assertSame('locked', $entitlement->fresh()->status);
                $this->assertEntitlements($order, 'locked');
            }
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reject_requires_a_bounded_reason(): void
    {
        [$branch, $order] = $this->manualOrder();
        $admin = $this->admin($branch, canVerify: true);
        $action = app(VerifyManualTransfer::class);

        foreach (['   ', str_repeat('x', 501)] as $reason) {
            try {
                $action->reject($admin, $order->id, (string) $order->proof_object_key, $reason);
                $this->fail('Invalid rejection reason should throw.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rejection_reason', $exception->errors());
            }
        }

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertEntitlements($order, 'locked');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_xendit_or_manual_order_without_a_proof_cannot_be_verified(): void
    {
        [$branch, $order] = $this->manualOrder(proof: false);
        $admin = $this->admin($branch, canVerify: true);
        $action = app(VerifyManualTransfer::class);

        try {
            $action->approve($admin, $order->id, 'manual/missing-proof.jpg');
            $this->fail('Proof is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_proof', $exception->errors());
        }

        [$xenditBranch, $xenditOrder] = $this->manualOrder(methodCode: 'xendit');

        try {
            $action->approve(
                $this->admin($xenditBranch, canVerify: true),
                $xenditOrder->id,
                (string) $xenditOrder->proof_object_key,
            );
            $this->fail('Xendit orders cannot be manually verified.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_proof', $exception->errors());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array{Branch, Order, Entitlement} */
    private function manualOrder(bool $proof = true, string $methodCode = 'manual_transfer'): array
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: $methodCode,
            amount: 250_000,
            proofObjectKey: $proof ? 'manual/private-proof.jpg' : null,
            orderMetadata: $proof ? [
                'manual_payment_proof' => [
                    'disk' => 'payment-proofs',
                    'mime_type' => 'image/jpeg',
                ],
            ] : null,
        );

        return [$fixture['branch'], $fixture['order'], $fixture['entitlements']['ist']];
    }

    private function admin(Branch $branch, bool $canVerify): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Admin '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
            'can_verify_payments' => $canVerify,
        ]);
    }

    private function assertEntitlements(Order $order, string $status): void
    {
        $this->assertSame(
            ['dass21' => $status, 'ist' => $status],
            Entitlement::query()->where('order_id', $order->id)->orderBy('test_type')->pluck('status', 'test_type')->all(),
        );
    }
}
