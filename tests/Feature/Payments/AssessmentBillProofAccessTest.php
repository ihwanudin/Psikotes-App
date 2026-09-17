<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Policies\AssessmentBillPolicy;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentBillProofUrlIssuer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

final class AssessmentBillProofAccessTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $bill;

    private Admin $superAdmin;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Carbon::setTestNow('2024-02-29 10:15:00+07:00');
        Storage::fake('payment-proofs');
        $this->bill = $this->manualBillWithProof();
        $this->superAdmin = $this->admin(AdminRole::SuperAdmin, null, true);
    }

    public function test_policy_is_separate_and_only_authorizes_persisted_active_super_admin(): void
    {
        $policy = new AssessmentBillPolicy;
        $bill = AssessmentBill::query()->findOrFail($this->bill['bill']);

        $this->assertTrue($policy->viewAny($this->superAdmin));
        $this->assertTrue($policy->view($this->superAdmin, $bill));
        $this->assertTrue($policy->viewProof($this->superAdmin, $bill));
        $this->assertTrue(Gate::forUser($this->superAdmin)->allows('viewProof', $bill));
        $this->assertFalse(Gate::allows('viewProof', $bill));

        foreach ([AdminRole::BranchAdmin, AdminRole::Staff, AdminRole::Psychologist] as $role) {
            $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
                ? $this->bill['organization'] : null;
            $admin = $this->admin($role, $branch, true);
            $this->assertFalse($policy->viewAny($admin));
            $this->assertFalse($policy->view($admin, $bill));
            $this->assertFalse($policy->viewProof($admin, $bill));
        }

        $stale = $this->admin(AdminRole::SuperAdmin, null, true);
        DB::table('admins')->where('id', $stale->id)->update(['deleted_at' => now()]);
        $this->assertFalse($policy->viewProof($stale, $bill));
        $this->assertFalse($policy->viewAny(new Admin));
    }

    public function test_successful_access_uses_storage_outside_locks_and_audits_only_opaque_identity(): void
    {
        $disk = Storage::disk('payment-proofs');
        $url = 'https://private.example.test/proof?opaque=token';
        $issuedAt = Carbon::parse('2024-02-29 03:15:00+00:00')->toImmutable();
        $this->proxyDisk($disk,
            function (string $key) use ($disk): bool {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertNull(app(RlsContextRunner::class)->current());

                return $disk->exists($key);
            },
            function (string $key, \DateTimeInterface $expiresAt) use ($issuedAt, $url): string {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertNull(app(RlsContextRunner::class)->current());
                $this->assertSame($issuedAt->addMinutes(15)->getTimestamp(), $expiresAt->getTimestamp());
                Carbon::setTestNow(Carbon::now()->addHour());

                return $url;
            });

        $access = $this->issue($this->superAdmin);
        $this->assertSame($url, $access->url);
        $this->assertSame($issuedAt->addMinutes(15)->getTimestamp(), $access->expiresAt->getTimestamp());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $access->proofFingerprint);
        $this->assertFalse(property_exists($access, 'objectKey'));

        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.proof_temporary_url_issued')->sole();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((string) $this->superAdmin->id, $audit->actor_id);
        $this->assertSame(AssessmentBill::class, $audit->subject_type);
        $this->assertSame((string) $this->bill['bill'], $audit->subject_id);
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $context = json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'version' => 1,
            'source' => 'assessment_bill_manual_review',
            'proof_fingerprint' => $access->proofFingerprint,
            'url_expires_at' => $access->expiresAt->toIso8601String(),
        ], $context);
        $participant = DB::table('participants')->where('id', $this->bill['participant'])->sole();
        $attempt = DB::table('assessment_participants')->where('id', $this->bill['attempt'])->sole();
        $bill = DB::table('assessment_bills')->where('id', $this->bill['bill'])->sole();
        foreach ([$url, $this->bill['proofKey'], $participant->full_name, $participant->birth_date,
            $attempt->external_candidate_id, $bill->idempotency_key, $bill->request_hash] as $privateValue) {
            $this->assertStringNotContainsString((string) $privateValue, (string) $audit->context);
        }
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_actor_is_rejected_before_bill_lookup_or_storage(AdminRole $role): void
    {
        $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
            ? $this->bill['organization'] : null;
        $actor = $this->admin($role, $branch, true);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Storage::shouldReceive('disk')->never();

        $this->assertAccessError($actor, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
        $this->assertSame([], array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, 'assessment_bills'))));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function unauthorizedActors(): iterable
    {
        yield 'branch payer legacy flag' => [AdminRole::BranchAdmin];
        yield 'staff legacy flag' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }

    #[DataProvider('actorRaces')]
    public function test_actor_revoked_after_url_creation_is_rechecked_before_second_bill_lookup(string $mutation): void
    {
        $disk = Storage::disk('payment-proofs');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->proxyDisk($disk, fn (string $key): bool => $disk->exists($key),
            function () use (&$queries, $mutation): string {
                DB::table('admins')->where('id', $this->superAdmin->id)->update($mutation === 'deleted'
                    ? ['deleted_at' => now()] : ['role' => AdminRole::Staff->value]);
                $queries = [];

                return 'https://private.example.test/unreturned';
            });

        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
        $this->assertSame([], array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, 'assessment_bills'))));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function actorRaces(): iterable
    {
        yield 'soft deleted' => ['deleted'];
        yield 'role changed' => ['role'];
    }

    #[DataProvider('proofIdentityRaces')]
    public function test_proof_change_between_url_creation_and_second_recheck_is_stale_without_audit(string $mutation): void
    {
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, fn (string $key): bool => $disk->exists($key), function () use ($mutation): string {
            $changes = ['proof_object_key' => null, 'proof_checksum_sha256' => null,
                'proof_mime_type' => null, 'proof_size_bytes' => null, 'proof_uploaded_at' => null];
            if ($mutation === 'replacement') {
                $contents = 'replacement-proof';
                $changes = [
                    'proof_object_key' => 'assessment-bills/zz/'.str_repeat('z', 62).'.pdf',
                    'proof_checksum_sha256' => hash('sha256', $contents),
                    'proof_mime_type' => 'application/pdf',
                    'proof_size_bytes' => strlen($contents),
                    'proof_uploaded_at' => now(),
                ];
            }
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update($changes);

            return 'https://private.example.test/unreturned';
        });

        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function proofIdentityRaces(): iterable
    {
        yield 'replacement' => ['replacement'];
        yield 'metadata cleared' => ['cleared'];
    }

    public function test_missing_object_and_storage_failures_never_audit_or_return_access(): void
    {
        $disk = Storage::disk('payment-proofs');
        $disk->delete($this->bill['proofKey']);
        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
        $this->assertDatabaseCount('audit_logs', 0);

        $this->proxyDisk($disk, static function (): never {
            throw new RuntimeException('synthetic storage failure');
        }, static fn (): string => 'unused');
        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_UNAVAILABLE');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_temporary_url_driver_failure_is_sanitized_without_audit(): void
    {
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, fn (string $key): bool => $disk->exists($key), static function (): never {
            throw new \DomainException('driver path or credential must not escape');
        });

        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_UNAVAILABLE');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('invalidProofStates')]
    public function test_nonmanual_missing_or_corrupt_proof_fails_before_storage(array $changes): void
    {
        if (array_key_exists('method_code', $changes)) {
            DB::table('payment_methods')->where('id', $this->bill['method'])
                ->update(['code' => $changes['method_code']]);
            unset($changes['method_code']);
        }
        if ($changes !== []) {
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update($changes);
        }
        Storage::shouldReceive('disk')->never();

        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidProofStates(): iterable
    {
        yield 'missing proof' => [['proof_object_key' => null, 'proof_checksum_sha256' => null,
            'proof_mime_type' => null, 'proof_size_bytes' => null, 'proof_uploaded_at' => null]];
        yield 'non manual' => [['method_code' => 'xendit']];
        yield 'gateway linked' => [['gateway_ref' => 'provider']];
        yield 'wrong key namespace' => [['proof_object_key' => '../proof.pdf']];
        yield 'uppercase checksum' => [['proof_checksum_sha256' => str_repeat('A', 64)]];
        yield 'foreign MIME' => [['proof_mime_type' => 'text/plain']];
        yield 'oversize' => [['proof_size_bytes' => 5_120_001]];
        yield 'malformed timestamp' => [['proof_uploaded_at' => 'not-a-timestamp']];
    }

    public function test_audit_failure_rolls_back_and_each_successful_access_is_a_new_event(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_assessment_proof_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'assessment_bill.proof_temporary_url_issued'
            BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END
            SQL);
        try {
            $this->issue($this->superAdmin);
            $this->fail('Expected audit rollback.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('synthetic audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('audit_logs', 0);
        DB::statement('DROP TRIGGER reject_assessment_proof_audit');

        $this->issue($this->superAdmin);
        $this->issue($this->superAdmin);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'assessment_bill.proof_temporary_url_issued')->count());
    }

    public function test_invalid_reference_deleted_actor_and_ambient_context_fail_closed(): void
    {
        $this->assertAccessError($this->superAdmin, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND', 'ORDER_invalid');
        $deleted = $this->admin(AdminRole::SuperAdmin, null, false);
        $deleted->delete();
        $this->assertAccessError($deleted, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
        $this->assertAccessError(new Admin, 'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');

        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->issue($this->superAdmin));
    }

    public function test_ambient_rls_context_is_rejected_before_storage(): void
    {
        $this->expectException(LogicException::class);
        app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => $this->issue($this->superAdmin));
    }

    private function issue(Admin $actor, ?string $reference = null): object
    {
        return app(AssessmentBillProofUrlIssuer::class)->issue($actor, $reference ?? $this->bill['reference']);
    }

    private function assertAccessError(Admin $actor, string $message, ?string $reference = null): void
    {
        try {
            $this->issue($actor, $reference);
            $this->fail('Expected assessment bill proof access to fail closed.');
        } catch (\DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function manualBillWithProof(): array
    {
        $fixture = AssessmentAccessFixture::create();
        $method = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
        DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer']);
        $contents = "%PDF-1.4\nprivate proof\n%%EOF";
        $key = 'assessment-bills/aa/'.str_repeat('b', 62).'.pdf';
        Storage::disk('payment-proofs')->put($key, $contents, ['visibility' => 'private']);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => 'pending', 'gateway_ref' => null, 'invoice_url' => null,
            'proof_object_key' => $key, 'proof_checksum_sha256' => hash('sha256', $contents),
            'proof_mime_type' => 'application/pdf', 'proof_size_bytes' => strlen($contents),
            'proof_uploaded_at' => now()->subMinute(), 'expires_at' => now()->addHour(),
        ]);

        return [...$fixture, 'method' => $method, 'proofKey' => $key,
            'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference')];
    }

    private function admin(AdminRole $role, ?int $branchId, bool $flag): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branchId, 'name' => 'Synthetic proof reviewer',
            'email' => uniqid().'@example.test', 'password' => 'not-real',
            'role' => $role, 'can_verify_payments' => $flag,
        ]);
    }

    private function proxyDisk(FilesystemAdapter $real, callable $exists, callable $temporaryUrl): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->andReturnUsing($exists);
        $mock->shouldReceive('temporaryUrl')->andReturnUsing($temporaryUrl);
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
    }
}
