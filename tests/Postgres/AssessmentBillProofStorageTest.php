<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\StoreAssessmentBillProof;
use App\Data\Payments\AssessmentBillProofUpload;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** Runtime non-owner concurrency proves the optimistic proof fingerprint fence and cleanup. */
final class AssessmentBillProofStorageTest extends TestCase
{
    private array $fixture;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        Date::setTestNow(now()->startOfSecond());
        Storage::fake('payment-proofs');
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $fixture = AssessmentAccessFixture::create();
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
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'status' => 'pending', 'paid_at' => null, 'verified_at' => null,
                'verified_by_admin_id' => null, 'rejection_reason' => null,
                'gateway_ref' => null, 'invoice_url' => null,
                'proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
                'proof_size_bytes' => null, 'proof_uploaded_at' => null,
            ]);
            $adminId = Admin::query()->insertGetId([
                'branch_id' => $fixture['organization'], 'name' => 'Synthetic proof uploader',
                'email' => uniqid().'@example.test', 'password' => 'not-real',
                'role' => AdminRole::BranchAdmin->value, 'can_verify_payments' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [...$fixture, 'method' => $method, 'admin' => $adminId,
                'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference')];
        });
        $this->admin = app(RlsContextRunner::class)->runAsService(
            fn (): Admin => Admin::query()->findOrFail($this->fixture['admin']),
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
            DB::table('admins')->where('id', $this->fixture['admin'])->delete();
            DB::table('participants')->where('id', $this->fixture['participant'])->delete();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
            DB::table('packages')->where('id', $this->fixture['package'])->delete();
            DB::table('payment_methods')->where('id', $this->fixture['method'])->delete();
            DB::table('branches')->where('id', $organization)->delete();
        });
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_two_initial_uploads_store_before_persist_but_only_one_identity_and_object_survive(): void
    {
        $this->assertRuntimeRole();
        [$first, $second] = $this->race(null);

        $this->assertOneWinner($first, $second);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $bill = AssessmentBill::query()->findOrFail($this->fixture['bill']);
            $this->assertNotNull($bill->proof_object_key);
            $this->assertNotNull($bill->proof_checksum_sha256);
        });
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());
    }

    public function test_two_replacements_of_same_version_keep_one_new_identity_and_delete_old_and_loser(): void
    {
        $first = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
            $this->admin, $this->fixture['reference'], $this->pdf('first.pdf'), null,
        ));
        $oldKey = app(RlsContextRunner::class)->runAsService(fn (): string => (string) AssessmentBill::query()
            ->where('id', $this->fixture['bill'])->value('proof_object_key'));

        [$left, $right] = $this->race($first->proofFingerprint);

        $this->assertOneWinner($left, $right);
        Storage::disk('payment-proofs')->assertMissing($oldKey);
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());
    }

    private function assertRuntimeRole(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second */
    private function assertOneWinner(array $first, array $second): void
    {
        $results = [$first, $second];
        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => isset($result['fingerprint']))));
        $this->assertSame(1, count(array_filter($results,
            static fn (array $result): bool => ($result['error'] ?? null) === 'ASSESSMENT_BILL_PROOF_CONFLICT')));
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function race(?string $expected): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        try {
            foreach (['left.pdf', 'right.pdf'] as $filename) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create proof storage worker.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    stream_set_timeout($pair[1], 20);
                    try {
                        $identity = DB::selectOne('SELECT current_user AS name');
                        if ($identity->name !== 'psikotes_runtime') {
                            throw new RuntimeException('Worker must use runtime role.');
                        }
                        DB::statement("SET lock_timeout = '12s'");
                        $real = Storage::disk('payment-proofs');
                        $mock = Mockery::mock(FilesystemAdapter::class);
                        $mock->shouldReceive('put')->andReturnUsing(function (string $path, string $contents,
                            array $options) use ($real, $pair): bool {
                            $stored = $real->put($path, $contents, $options);
                            fwrite($pair[1], "stored\n");
                            if (fgets($pair[1]) !== "persist\n") {
                                throw new RuntimeException('Persist barrier timed out.');
                            }

                            return $stored;
                        });
                        $mock->shouldReceive('delete')->andReturnUsing(fn (string $path): bool => $real->delete($path));
                        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Start barrier timed out.');
                        }
                        $receipt = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
                            $this->admin, $this->fixture['reference'], $this->pdf($filename), $expected,
                        ));
                        $result = ['fingerprint' => $receipt->proofFingerprint, 'replaced' => $receipt->replaced];
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
                $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
            }
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            foreach ($workers as $worker) {
                $this->assertSame("stored\n", fgets($worker['socket']));
            }
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "persist\n");
            }
            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }

    private function pdf(string $filename): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, "%PDF-1.4\n{$filename}\n%%EOF");
    }
}
