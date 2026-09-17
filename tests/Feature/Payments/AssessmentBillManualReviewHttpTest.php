<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\AdminRole;
use App\Http\Controllers\AssessmentBillManualReviewController;
use App\Models\Admin;
use App\Models\AssessmentBill;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillManualReviewHttpTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $bill;

    private Admin $reviewer;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->freezeTime();
        Storage::fake('payment-proofs');
        $this->bill = $this->pendingManualBillWithProof();
        $this->reviewer = $this->admin(AdminRole::SuperAdmin, null, false);

        // Synthetic web routes prove the future method/CSRF boundary without production registration.
        Route::middleware('web')->get('/__test/assessment-bills/{billReference}/proof',
            [AssessmentBillManualReviewController::class, 'proof']);
        Route::middleware('web')->post('/__test/assessment-bills/{billReference}/review',
            [AssessmentBillManualReviewController::class, 'review']);
    }

    public function test_proof_redirect_is_opaque_private_and_never_cached(): void
    {
        $disk = Storage::disk('payment-proofs');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->once()->andReturnUsing(fn (string $key): bool => $disk->exists($key));
        $mock->shouldReceive('temporaryUrl')->once()->andReturn('https://proof.example.test/opaque-token');
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);

        $response = $this->actingAs($this->reviewer, 'admin')->get($this->proofUrl());
        $response->assertRedirect('https://proof.example.test/opaque-token');
        $this->assertPrivacyHeaders($response);
        $this->assertStringNotContainsString($this->bill['proofKey'], $response->getContent());
        $this->assertStringNotContainsString('Synthetic Candidate', $response->getContent());
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin', 'actor_id' => (string) $this->reviewer->id,
            'action' => 'assessment_bill.proof_temporary_url_issued',
            'subject_type' => AssessmentBill::class, 'subject_id' => (string) $this->bill['bill'],
        ]);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_guest_and_legacy_admin_roles_receive_generic_not_found_without_oracle(?AdminRole $role): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $request = $this;
        if ($role !== null) {
            $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
                ? $this->bill['organization'] : null;
            $request = $this->actingAs($this->admin($role, $branch, true), 'admin');
        }

        $proof = $request->get($this->proofUrl())->assertNotFound();
        $this->assertPrivacyHeaders($proof);
        $this->assertGenericBody($proof->getContent());
        $request->postJson($this->reviewUrl(), $this->approvePayload())->assertNotFound();
        $this->assertSame([], array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, 'assessment_bills'))));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function unauthorizedRoles(): iterable
    {
        yield 'guest' => [null];
        yield 'branch payer legacy flag' => [AdminRole::BranchAdmin];
        yield 'staff legacy flag' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }

    #[DataProvider('staleReviewerMutations')]
    public function test_deleted_or_role_changed_super_admin_fails_locked_recheck_for_both_adapters(array $mutation): void
    {
        DB::table('admins')->where('id', $this->reviewer->id)->update($mutation);

        $proof = $this->actingAs($this->reviewer, 'admin')->get($this->proofUrl())->assertNotFound();
        $this->assertPrivacyHeaders($proof);
        $this->postJson($this->reviewUrl(), $this->approvePayload())->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function staleReviewerMutations(): iterable
    {
        yield 'deleted' => [['deleted_at' => '2026-09-01 00:00:00']];
        yield 'role changed' => [['role' => AdminRole::Staff->value]];
    }

    #[DataProvider('invalidDecisionPayloads')]
    public function test_decision_request_rejects_malformed_or_ambiguous_payload(array $payload): void
    {
        $response = $this->actingAs($this->reviewer, 'admin')->postJson($this->reviewUrl(), $payload);
        $response->assertUnprocessable();
        $this->assertGenericBody($response->getContent());
        $this->assertSame('pending', DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('status'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidDecisionPayloads(): iterable
    {
        $valid = ['proof_fingerprint' => str_repeat('a', 64), 'decision' => 'APPROVE'];
        yield 'missing fingerprint' => [['decision' => 'APPROVE']];
        yield 'uppercase fingerprint' => [[...$valid, 'proof_fingerprint' => str_repeat('A', 64)]];
        yield 'unknown decision' => [[...$valid, 'decision' => 'PAID']];
        yield 'reject missing code' => [[...$valid, 'decision' => 'REJECT']];
        yield 'reject null code' => [[...$valid, 'decision' => 'REJECT', 'rejection_code' => null]];
        yield 'reject unknown code' => [[...$valid, 'decision' => 'REJECT', 'rejection_code' => 'FREE_TEXT']];
        yield 'approve with code' => [[...$valid, 'rejection_code' => 'UNREADABLE_PROOF']];
        yield 'body bill authority' => [[...$valid, 'bill_reference' => 'AB_01K3H9M5YXB62D9QK7E5V2G8Z9']];
        yield 'unknown root key' => [[...$valid, 'amount' => 1]];
        yield 'wrong type' => [['proof_fingerprint' => [], 'decision' => ['APPROVE']]];
    }

    public function test_approve_and_exact_replay_return_minimal_contract_without_duplicate_audit(): void
    {
        $payload = $this->approvePayload($this->bill['fingerprint']);
        $first = $this->actingAs($this->reviewer, 'admin')->postJson($this->reviewUrl(), $payload)
            ->assertOk()->assertExactJson(['data' => ['result' => 'settled']]);
        $this->assertDecisionBodyPrivate($first->getContent());
        $second = $this->postJson($this->reviewUrl(), $payload)
            ->assertOk()->assertExactJson(['data' => ['result' => 'replayed']]);
        $this->assertDecisionBodyPrivate($second->getContent());
        $this->assertSame('paid', DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->postJson($this->reviewUrl(), [
            'proof_fingerprint' => $this->bill['fingerprint'], 'decision' => 'REJECT',
            'rejection_code' => 'UNREADABLE_PROOF',
        ])->assertConflict();
    }

    public function test_reject_and_exact_replay_return_minimal_contract_without_settlement(): void
    {
        $payload = ['proof_fingerprint' => $this->bill['fingerprint'], 'decision' => 'REJECT',
            'rejection_code' => 'UNREADABLE_PROOF'];
        $this->actingAs($this->reviewer, 'admin')->postJson($this->reviewUrl(), $payload)
            ->assertOk()->assertExactJson(['data' => ['result' => 'rejected']]);
        $this->postJson($this->reviewUrl(), $payload)
            ->assertOk()->assertExactJson(['data' => ['result' => 'replayed']]);
        $this->assertSame('rejected', DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('status'));
        $this->assertNull(DB::table('assessment_bill_items')->where('id', $this->bill['item'])->value('settled_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.rejected')->count());
    }

    public function test_stale_or_opposite_decision_maps_conflict_and_invalid_state_maps_generic_422(): void
    {
        $this->actingAs($this->reviewer, 'admin')
            ->postJson($this->reviewUrl(), $this->approvePayload(str_repeat('0', 64)))
            ->assertConflict();
        $this->assertSame('pending', DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('status'));
    }

    #[DataProvider('invalidReviewStates')]
    public function test_state_scope_channel_and_proof_errors_map_generic_422(array $changes): void
    {
        if (array_key_exists('method_code', $changes)) {
            DB::table('payment_methods')->where('id', $this->bill['method'])
                ->update(['code' => $changes['method_code']]);
            unset($changes['method_code']);
        }
        if ($changes !== []) {
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update($changes);
        }

        $invalid = $this->actingAs($this->reviewer, 'admin')
            ->postJson($this->reviewUrl(), $this->approvePayload($this->bill['fingerprint']))
            ->assertUnprocessable();
        $this->assertGenericBody($invalid->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidReviewStates(): iterable
    {
        yield 'state' => [['status' => 'expired']];
        yield 'scope' => [['item_count' => 2]];
        yield 'channel' => [['method_code' => 'xendit']];
        yield 'proof' => [['proof_checksum_sha256' => str_repeat('A', 64)]];
    }

    public function test_proof_missing_and_invalid_reference_map_without_sensitive_details(): void
    {
        Storage::disk('payment-proofs')->delete($this->bill['proofKey']);
        $missing = $this->actingAs($this->reviewer, 'admin')->get($this->proofUrl())->assertNotFound();
        $this->assertPrivacyHeaders($missing);
        $this->assertGenericBody($missing->getContent());

        $invalid = $this->get('/__test/assessment-bills/ORDER_invalid/proof')->assertNotFound();
        $this->assertPrivacyHeaders($invalid);
        $this->postJson('/__test/assessment-bills/ORDER_invalid/review', $this->approvePayload())
            ->assertNotFound();
    }

    public function test_storage_unavailable_returns_generic_503_and_privacy_headers(): void
    {
        $disk = Storage::disk('payment-proofs');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->andReturnUsing(fn (string $key): bool => $disk->exists($key));
        $mock->shouldReceive('temporaryUrl')->andThrow(new RuntimeException('credential at C:\\secret'));
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);

        $response = $this->actingAs($this->reviewer, 'admin')->get($this->proofUrl())->assertStatus(503);
        $this->assertPrivacyHeaders($response);
        $this->assertGenericBody($response->getContent());
        $this->assertStringNotContainsString('credential', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_review_requires_post_on_synthetic_web_middleware_and_production_route_is_absent(): void
    {
        $this->get($this->reviewUrl())->assertStatus(405);
        $route = collect(Route::getRoutes()->getRoutes())->first(
            static fn ($route): bool => $route->uri() === '__test/assessment-bills/{billReference}/review',
        );
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->middleware());
        $this->assertContains(PreventRequestForgery::class, app('router')->getMiddlewareGroups()['web']);
        $this->assertStringNotContainsString('AssessmentBillManualReviewController',
            (string) file_get_contents(base_path('routes/web.php')));
    }

    private function proofUrl(): string
    {
        return "/__test/assessment-bills/{$this->bill['reference']}/proof";
    }

    private function reviewUrl(): string
    {
        return "/__test/assessment-bills/{$this->bill['reference']}/review";
    }

    private function approvePayload(?string $fingerprint = null): array
    {
        return ['proof_fingerprint' => $fingerprint ?? str_repeat('a', 64), 'decision' => 'APPROVE'];
    }

    private function assertPrivacyHeaders($response): void
    {
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    private function assertGenericBody(string $body): void
    {
        $this->assertStringNotContainsString($this->bill['reference'], $body);
        $this->assertStringNotContainsString($this->bill['proofKey'], $body);
        $this->assertStringNotContainsString('Synthetic Candidate', $body);
    }

    private function assertDecisionBodyPrivate(string $body): void
    {
        $this->assertGenericBody($body);
        $this->assertStringNotContainsString((string) $this->bill['organization'], $body);
        $this->assertStringNotContainsString((string) $this->bill['amount'], $body);
    }

    private function pendingManualBillWithProof(): array
    {
        $fixture = AssessmentAccessFixture::create();
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Synthetic Candidate']);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'assessment_status' => 'PROVISIONED', 'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
            ->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        $method = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
        DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer', 'is_active' => false]);
        $contents = "%PDF-1.4\nprivate proof\n%%EOF";
        $key = 'assessment-bills/aa/'.str_repeat('b', 62).'.pdf';
        $uploadedAt = now()->subMinute()->toImmutable()->utc()->startOfSecond();
        Storage::disk('payment-proofs')->put($key, $contents, ['visibility' => 'private']);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => 'pending', 'paid_at' => null, 'verified_at' => null,
            'verified_by_admin_id' => null, 'rejection_reason' => null,
            'gateway_ref' => null, 'invoice_url' => null,
            'proof_object_key' => $key, 'proof_checksum_sha256' => hash('sha256', $contents),
            'proof_mime_type' => 'application/pdf', 'proof_size_bytes' => strlen($contents),
            'proof_uploaded_at' => $uploadedAt, 'expires_at' => now()->addHour(),
        ]);
        $reference = (string) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference');
        $amount = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('amount');
        $fingerprint = hash('sha256', implode("\0", [
            $key, hash('sha256', $contents), 'application/pdf', (string) strlen($contents),
            $uploadedAt->format('Y-m-d\TH:i:s.u\Z'),
        ]));

        return [...$fixture, 'method' => $method, 'proofKey' => $key, 'reference' => $reference,
            'fingerprint' => $fingerprint, 'amount' => $amount];
    }

    private function admin(AdminRole $role, ?int $branchId, bool $legacyFlag): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branchId, 'name' => 'Synthetic reviewer',
            'email' => uniqid().'@example.test', 'password' => 'not-real',
            'role' => $role, 'can_verify_payments' => $legacyFlag,
        ]);
    }
}
