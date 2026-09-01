<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentBillProofUrlIssuer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** Runtime non-owner process proof for the post-storage reviewer recheck. */
final class AssessmentBillProofAccessTest extends TestCase
{
    private array $fixture;

    private Admin $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(now()->startOfSecond());
        Storage::fake('payment-proofs');
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $fixture = AssessmentAccessFixture::create();
            $method = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
            DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer']);
            $contents = "%PDF-1.4\nprivate proof\n%%EOF";
            $key = 'assessment-bills/aa/'.str_repeat('b', 62).'.pdf';
            Storage::disk('payment-proofs')->put($key, $contents, ['visibility' => 'private']);
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'status' => 'pending', 'paid_at' => null, 'verified_at' => null,
                'verified_by_admin_id' => null, 'rejection_reason' => null,
                'gateway_ref' => null, 'invoice_url' => null,
                'proof_object_key' => $key, 'proof_checksum_sha256' => hash('sha256', $contents),
                'proof_mime_type' => 'application/pdf', 'proof_size_bytes' => strlen($contents),
                'proof_uploaded_at' => now()->subMinute(), 'expires_at' => now()->addHour(),
            ]);
            $reviewer = Admin::query()->insertGetId([
                'branch_id' => null, 'name' => 'Synthetic proof reviewer',
                'email' => uniqid().'@example.test', 'password' => 'not-real',
                'role' => AdminRole::SuperAdmin->value, 'can_verify_payments' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [...$fixture, 'method' => $method, 'reviewer' => $reviewer, 'proofKey' => $key,
                'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference')];
        });
        $this->reviewer = app(RlsContextRunner::class)->runAsService(
            fn (): Admin => Admin::query()->findOrFail($this->fixture['reviewer']),
        );
    }

    protected function tearDown(): void
    {
        Storage::disk('payment-proofs')->deleteDirectory('/');
        app(RlsContextRunner::class)->runAsService(function (): void {
            $organization = $this->fixture['organization'];
            DB::table('audit_logs')->where('branch_id', $organization)->delete();
            foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges',
                'assessment_participants', 'integration_clients'] as $table) {
                DB::table($table)->where('organization_id', $organization)->delete();
            }
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $this->fixture['participant'])->delete();
            }
            DB::table('admins')->where('id', $this->fixture['reviewer'])->delete();
            DB::table('participants')->where('id', $this->fixture['participant'])->delete();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
            DB::table('packages')->where('id', $this->fixture['package'])->delete();
            DB::table('payment_methods')->where('id', $this->fixture['method'])->delete();
            DB::table('branches')->where('id', $organization)->delete();
        });
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_reviewer_revocation_committed_after_url_creation_fences_return_and_audit(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($pair);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                DB::statement("SET lock_timeout = '12s'");
                $real = Storage::disk('payment-proofs');
                $mock = Mockery::mock(FilesystemAdapter::class);
                $mock->shouldReceive('exists')->andReturnUsing(fn (string $key): bool => $real->exists($key));
                $mock->shouldReceive('temporaryUrl')->andReturnUsing(function () use ($pair): string {
                    fwrite($pair[1], "url-created\n");
                    if (fgets($pair[1]) !== "recheck\n") {
                        throw new RuntimeException('Reviewer revocation barrier timed out.');
                    }

                    return 'https://private.example.test/unreturned';
                });
                Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
                app(AssessmentBillProofUrlIssuer::class)->issue($this->reviewer, $this->fixture['reference']);
                $result = ['unexpected' => true];
            } catch (Throwable $exception) {
                $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
            }
            fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }

        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        try {
            $this->assertSame("url-created\n", fgets($pair[0]));
            app(RlsContextRunner::class)->runAsService(fn (): int => DB::table('admins')
                ->where('id', $this->fixture['reviewer'])->update(['deleted_at' => now()]));
            fwrite($pair[0], "recheck\n");
            $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND', $result['error'] ?? null);
            app(RlsContextRunner::class)->runAsService(function (): void {
                $this->assertSame(0, DB::table('audit_logs')
                    ->where('action', 'assessment_bill.proof_temporary_url_issued')->count());
                $this->assertNotNull(AssessmentBill::query()->findOrFail($this->fixture['bill'])->proof_object_key);
            });
        } finally {
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
}
