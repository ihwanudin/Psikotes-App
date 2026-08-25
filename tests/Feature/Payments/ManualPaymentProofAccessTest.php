<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManualPaymentProofAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('payment-proofs');
    }

    public function test_authorized_branch_admin_gets_a_short_lived_url_and_access_is_audited(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        [$branch, $order] = $this->orderWithProof('A');
        $admin = $this->admin($branch, canVerify: true);

        $response = $this->actingAs($admin, 'admin')
            ->get("/admin/manual-payment-proofs/{$order->public_id}/open")
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $query = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), (int) ($parameters['expiration'] ?? 0));
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'action' => 'manual_payment_proof.temporary_url_issued',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->public_id,
        ]);
    }

    public function test_guest_admin_without_ability_and_cross_branch_admin_are_denied_without_audit(): void
    {
        [$branchA, $order] = $this->orderWithProof('A');
        [$branchB] = $this->orderWithProof('B');
        $url = "/admin/manual-payment-proofs/{$order->public_id}/open";

        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs($this->admin($branchA, canVerify: false), 'admin')
            ->get($url)
            ->assertForbidden();
        $this->actingAs($this->admin($branchB, canVerify: true), 'admin')
            ->get($url)
            ->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_order_without_a_proof_cannot_issue_a_url(): void
    {
        [$branch, $order] = $this->orderWithProof('A');
        Storage::disk('payment-proofs')->delete((string) $order->proof_object_key);
        $order->forceFill(['proof_object_key' => null, 'metadata' => null])->save();

        $this->actingAs($this->admin($branch, canVerify: true), 'admin')
            ->get("/admin/manual-payment-proofs/{$order->public_id}/open")
            ->assertNotFound();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array{Branch, Order} */
    private function orderWithProof(string $suffix): array
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
        $key = 'manual/'.Str::lower(Str::random(64)).'.jpg';
        Storage::disk('payment-proofs')->put($key, 'private-proof', 'private');
        $order = Order::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'payment_method_id' => $method->id,
            'status' => 'pending',
            'amount' => 250_000,
            'currency' => 'IDR',
            'proof_object_key' => $key,
            'metadata' => [
                'manual_payment_proof' => [
                    'disk' => 'payment-proofs',
                    'mime_type' => 'image/jpeg',
                ],
            ],
        ]);

        return [$branch, $order];
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
