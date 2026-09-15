<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Support\DirectPublicPaymentFixture;

final class ManualTransferFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_authorized_admin_sees_only_its_branch_manual_orders(): void
    {
        [$branchA, $orderA] = $this->manualOrder('A');
        [, $orderB] = $this->manualOrder('B');
        $admin = $this->admin($branchA, canVerify: true);
        $this->actingAs($admin, 'admin');

        $this->get('/admin/orders')->assertOk();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$orderA])
            ->assertCanNotSeeTableRecords([$orderB])
            ->assertActionVisible(TestAction::make('openProof')->table($orderA))
            ->assertActionVisible(TestAction::make('approve')->table($orderA))
            ->assertActionVisible(TestAction::make('reject')->table($orderA));
    }

    public function test_admin_without_payment_ability_cannot_open_the_resource(): void
    {
        [$branch] = $this->manualOrder('A');
        $admin = $this->admin($branch, canVerify: false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/orders')
            ->assertForbidden();
    }

    public function test_filament_approve_action_smoke_unlocks_the_order_once(): void
    {
        [$branch, $order, $entitlement] = $this->manualOrder('A');
        $admin = $this->admin($branch, canVerify: true);
        $this->actingAs($admin, 'admin');

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('approve')->table($order))
            ->assertNotified();

        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_filament_reject_action_requires_and_stores_a_reason(): void
    {
        [$branch, $order, $entitlement] = $this->manualOrder('A');
        $admin = $this->admin($branch, canVerify: true);
        $this->actingAs($admin, 'admin');

        Livewire::test(ListOrders::class)
            ->callAction(
                TestAction::make('reject')->table($order),
                data: ['rejection_reason' => 'Bukti transfer tidak terbaca.'],
            )
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame('rejected', $order->fresh()->status->value);
        $this->assertSame('Bukti transfer tidak terbaca.', $order->fresh()->rejection_reason);
        $this->assertSame('locked', $entitlement->fresh()->status);
    }

    /** @return array{Branch, Order, Entitlement} */
    private function manualOrder(string $suffix): array
    {
        $branch = Branch::query()->create([
            'code' => "BR-{$suffix}",
            'name' => "Cabang {$suffix}",
            'ref_code' => "REF-{$suffix}",
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
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

        $publicId = (string) Str::ulid();
        $case = DirectPublicPaymentFixture::caseFor($participant, $branch, $publicId, 250_000);
        $order = Order::query()->create([
            'public_id' => $publicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $method->id,
            'status' => 'pending',
            'amount' => 250_000,
            'currency' => 'IDR',
            'proof_object_key' => 'manual/proof-'.Str::lower($suffix).'.jpg',
            'metadata' => [
                'manual_payment_proof' => [
                    'disk' => 'payment-proofs',
                    'mime_type' => 'image/jpeg',
                ],
            ],
        ]);
        $entitlement = Entitlement::query()->create([
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'assessment_case_id' => $case->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);

        return [$branch, $order, $entitlement];
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
}
