<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
        Carbon::setTestNow('2024-02-29 10:15:00+07:00');
        [$branch, $order] = $this->orderWithProof('A');
        $admin = $this->admin($branch, canVerify: true);
        $participant = $order->participant;
        $issuedAt = Carbon::parse('2024-02-29 03:15:00+00:00')->toImmutable();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUrl')->twice()->andReturnUsing(
            function (string $path, \DateTimeInterface $expiresAt): string {
                Carbon::setTestNow(Carbon::now()->addHour());

                return 'https://private.example.test/synthetic-proof?expiration='.$expiresAt->getTimestamp();
            },
        );
        Storage::shouldReceive('disk')->with('payment-proofs')->twice()->andReturn($disk);

        $response = $this->actingAs($admin, 'admin')
            ->get("/admin/manual-payment-proofs/{$order->public_id}/open")
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $query = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);
        $this->assertSame($issuedAt->addMinutes(15)->getTimestamp(), (int) ($parameters['expiration'] ?? 0));
        $audit = (array) DB::table('audit_logs')->sole();
        $this->assertSame('2024-02-29 03:15:00', $audit['occurred_at']);
        $this->assertSame('2029-02-28 03:15:00', $audit['expires_at']);
        $this->assertSame([
            'url_expires_at' => $issuedAt->addMinutes(15)->toIso8601String(),
        ], json_decode((string) $audit['context'], true, 512, JSON_THROW_ON_ERROR));
        foreach ([
            $location,
            $order->proof_object_key,
            $participant->full_name,
            $participant->phone,
            $participant->birth_date?->format('Y-m-d'),
            $admin->name,
            $admin->email,
        ] as $privateValue) {
            $this->assertStringNotContainsString((string) $privateValue, (string) $audit['context']);
        }
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'action' => 'manual_payment_proof.temporary_url_issued',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->public_id,
        ]);

        Carbon::setTestNow('2024-02-29 10:20:00+07:00');
        $this->actingAs($admin, 'admin')
            ->get("/admin/manual-payment-proofs/{$order->public_id}/open")
            ->assertRedirect();

        $this->assertDatabaseCount('audit_logs', 2);
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
        $package = TestPackage::query()->create([
            'code' => "PACKAGE-{$suffix}",
            'name' => "Paket {$suffix}",
            'amount' => 250_000,
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
        $key = 'manual/'.Str::lower(Str::random(64)).'.jpg';
        Storage::disk('payment-proofs')->put($key, 'private-proof', 'private');
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
