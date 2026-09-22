<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\GrantBridgeFunding;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Services\Payments\Exceptions\InvalidOrderTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GrantBridgeFundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-09-22 10:00:00+07:00');
    }

    public function test_super_admin_grants_bridge_funding_and_unlocks_entitlements_identically_to_paying(): void
    {
        [$branch, $order, $entitlement] = $this->pendingOrder();
        $admin = $this->superAdmin();
        $action = app(GrantBridgeFunding::class);

        $result = $action->handle($admin, $order->id, 'Surat instruksi holding No. 001/HC/2026');

        $this->assertSame('bridge_funded', $result->status->value);
        $order->refresh();
        $entitlement->refresh();
        $this->assertSame('bridge_funded', $order->status->value);
        $this->assertNull($order->paid_at, 'BridgeFunded must never claim money was actually received.');
        $this->assertSame('ready', $entitlement->status);
        $this->assertTrue($entitlement->ready_at?->equalTo(Date::now()) ?? false);

        $method = PaymentMethod::query()->findOrFail($order->payment_method_id);
        $this->assertSame('bridge_funding', $method->code);
        $this->assertFalse($method->is_active, 'The synthetic payment method must never be selectable by participants.');

        $grant = DB::table('bridge_funding_grants')->where('order_id', $order->id)->sole();
        $this->assertSame($order->amount, $grant->amount);
        $this->assertSame($admin->id, $grant->approved_by_admin_id);
        $this->assertSame('Surat instruksi holding No. 001/HC/2026', $grant->management_reference);
        $this->assertSame('invoiced', $grant->status);

        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'actor_id' => (string) $admin->id,
            'action' => 'order.bridge_funding_granted',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->public_id,
        ]);

        // Activation notification parity: closes Lead's headline finding as
        // a regression test, not just an argument.
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'participant.activation',
            'aggregate_type' => Order::class,
            'aggregate_id' => $order->public_id,
            'status' => 'pending',
        ]);
    }

    public function test_grant_is_idempotent_on_replay(): void
    {
        [, $order] = $this->pendingOrder();
        $admin = $this->superAdmin();
        $action = app(GrantBridgeFunding::class);

        $action->handle($admin, $order->id, 'Surat instruksi holding No. 001/HC/2026');
        $action->handle($admin, $order->id, 'Surat instruksi holding No. 001/HC/2026 (retried)');

        $this->assertSame(1, DB::table('bridge_funding_grants')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.bridge_funding_granted')->count());
    }

    public function test_branch_admin_cannot_approve_bridge_funding(): void
    {
        [$branch, $order] = $this->pendingOrder();
        $admin = Admin::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Branch Admin',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
        ]);

        $this->expectException(AuthorizationException::class);

        app(GrantBridgeFunding::class)->handle($admin, $order->id, 'Surat instruksi holding No. 002/HC/2026');
    }

    public function test_blank_management_reference_is_rejected(): void
    {
        [, $order] = $this->pendingOrder();
        $admin = $this->superAdmin();

        $this->expectException(ValidationException::class);

        app(GrantBridgeFunding::class)->handle($admin, $order->id, '   ');
    }

    public function test_amount_cap_is_enforced_when_configured(): void
    {
        [, $order] = $this->pendingOrder(amount: 500_000);
        config()->set('bridge_funding.max_amount', 100_000);
        $admin = $this->superAdmin();

        $this->expectException(ValidationException::class);

        app(GrantBridgeFunding::class)->handle($admin, $order->id, 'Surat instruksi holding No. 003/HC/2026');
    }

    public function test_amount_cap_unlimited_by_default(): void
    {
        [, $order] = $this->pendingOrder(amount: 500_000_000);
        $this->assertNull(config('bridge_funding.max_amount'));
        $admin = $this->superAdmin();

        $result = app(GrantBridgeFunding::class)->handle($admin, $order->id, 'Surat instruksi holding No. 004/HC/2026');

        $this->assertSame('bridge_funded', $result->status->value);
    }

    public function test_already_paid_order_cannot_be_bridge_funded(): void
    {
        [, $order] = $this->pendingOrder();
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $admin = $this->superAdmin();

        $this->expectException(InvalidOrderTransition::class);

        app(GrantBridgeFunding::class)->handle($admin, $order->id, 'Surat instruksi holding No. 005/HC/2026');
    }

    /** @return array{Branch, Order, Entitlement} */
    private function pendingOrder(int $amount = 250_000): array
    {
        $suffix = Str::upper(Str::random(8));
        $branch = Branch::query()->create([
            'code' => "BR-{$suffix}",
            'name' => "Cabang {$suffix}",
            'ref_code' => "REF-{$suffix}",
        ]);
        $package = TestPackage::query()->create([
            'code' => "PACKAGE-{$suffix}",
            'name' => "Paket {$suffix}",
            'amount' => $amount,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        foreach (['ist', 'dass21'] as $testType) {
            $package->items()->create(['test_type' => $testType]);
        }
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $package->id,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => "Peserta {$suffix}",
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
        ]);
        $method = PaymentMethod::query()->where('code', 'manual_transfer')->first();

        if ($method === null) {
            $method = new PaymentMethod;
            $method->forceFill([
                'code' => 'manual_transfer',
                'display_name' => 'Transfer Manual',
                'is_active' => true,
            ])->save();
        }

        $orderPublicId = (string) Str::ulid();
        $case = AssessmentCase::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'KAIGO',
        ]);
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $method->id,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => 'IDR',
        ]);
        $entitlement = Entitlement::query()->create([
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'order_id' => $order->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);
        Entitlement::query()->create([
            'participant_id' => $participant->id,
            'assessment_case_id' => null,
            'order_id' => $order->id,
            'test_type' => 'dass21',
            'status' => 'locked',
        ]);

        return [$branch, $order, $entitlement];
    }

    private function superAdmin(): Admin
    {
        return Admin::query()->create([
            'branch_id' => null,
            'name' => 'Super Admin '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
        ]);
    }
}
