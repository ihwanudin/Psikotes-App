<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\StoreAssessmentBillProof;
use App\Data\Payments\AssessmentBillProofUpload;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillProofStorageTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $bill;

    private Admin $branchAdmin;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->freezeTime();
        Storage::fake('payment-proofs');
        $this->bill = $this->pendingManualBill();
        $this->branchAdmin = $this->admin(AdminRole::BranchAdmin, $this->bill['organization']);
    }

    #[DataProvider('invalidUploadShapes')]
    public function test_typed_upload_rejects_invalid_reference_or_fingerprint(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssessmentBillProofUpload(...$values);
    }

    public static function invalidUploadShapes(): iterable
    {
        $valid = ['uploader' => new Admin, 'billReference' => 'AB_01K3H9M5YXB62D9QK7E5V2G8Z9',
            'proof' => UploadedFile::fake()->image('proof.jpg'), 'expectedCurrentProofFingerprint' => null];
        yield 'foreign namespace' => [[...$valid, 'billReference' => 'ORDER_01K3H9M5YXB62D9QK7E5V2G8Z9']];
        yield 'uppercase fingerprint' => [[...$valid, 'expectedCurrentProofFingerprint' => str_repeat('A', 64)]];
        yield 'short fingerprint' => [[...$valid, 'expectedCurrentProofFingerprint' => str_repeat('a', 63)]];
    }

    #[DataProvider('validProofTypes')]
    public function test_branch_payer_stores_detected_private_proof_without_key_or_pii_in_receipt(string $type): void
    {
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, function (string $path, string $contents, array $options) use ($disk): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());

            return $disk->put($path, $contents, $options);
        });
        $receipt = $this->execute($this->upload($this->branchAdmin, $this->proof($type)));
        $bill = AssessmentBill::query()->findOrFail($this->bill['bill']);

        $this->assertFalse($receipt->replaced);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $receipt->proofFingerprint);
        $this->assertFalse(property_exists($receipt, 'objectKey'));
        $this->assertMatchesRegularExpression('/^assessment-bills\/[a-z0-9]{2}\/[a-z0-9]{62}\.(jpg|png|pdf)$/D', (string) $bill->proof_object_key);
        $this->assertStringNotContainsStringIgnoringCase('candidate', (string) $bill->proof_object_key);
        $this->assertStringNotContainsString($bill->public_reference, (string) $bill->proof_object_key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $bill->proof_checksum_sha256);
        $this->assertContains($bill->proof_mime_type, ['image/jpeg', 'image/png', 'application/pdf']);
        $this->assertGreaterThanOrEqual(1, $bill->proof_size_bytes);
        $this->assertLessThanOrEqual(5_120_000, $bill->proof_size_bytes);
        $this->assertNotNull($bill->proof_uploaded_at);
        $disk->assertExists((string) $bill->proof_object_key);
        $this->assertSame('private', config('filesystems.disks.payment-proofs.visibility'));
    }

    public static function validProofTypes(): iterable
    {
        yield 'jpeg' => ['jpeg'];
        yield 'png' => ['png'];
        yield 'pdf' => ['pdf'];
        yield 'maximum bytes' => ['max'];
    }

    public function test_exact_participant_principal_can_store_for_self_bill_only(): void
    {
        $this->makeSelfBill();
        $principal = new AssessmentPrincipal($this->bill['participant'], $this->bill['organization'], $this->bill['attempt']);
        $this->assertFalse($this->execute($this->upload($principal, $this->proof('jpeg')))->replaced);
        $this->clearProof();
        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')), 'ASSESSMENT_BILL_PROOF_NOT_FOUND');
        $this->makeOrganizationBill();
        $this->assertStorageError($this->upload($principal, $this->proof('jpeg')), 'ASSESSMENT_BILL_PROOF_NOT_FOUND');
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_unauthorized_admin_is_rejected_before_bill_lookup_or_storage(AdminRole $role): void
    {
        $admin = $this->admin($role, $this->bill['organization'], true);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertStorageError($this->upload($admin, $this->proof('jpeg')), 'ASSESSMENT_BILL_PROOF_NOT_FOUND');
        $this->assertSame([], array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, '"assessment_bills"'))));
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    public static function unauthorizedRoles(): iterable
    {
        yield 'super admin' => [AdminRole::SuperAdmin];
        yield 'staff flag true' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }

    public function test_foreign_branch_stale_participant_and_unsaved_admin_are_not_oracles(): void
    {
        $foreign = AssessmentAccessFixture::create();
        $foreignAdmin = $this->admin(AdminRole::BranchAdmin, $foreign['organization'], true);
        $stale = new AssessmentPrincipal($this->bill['participant'], $this->bill['organization'], $foreign['attempt']);
        foreach ([$foreignAdmin, $stale, new Admin] as $uploader) {
            $this->assertStorageError($this->upload($uploader, $this->proof('jpeg')), 'ASSESSMENT_BILL_PROOF_NOT_FOUND');
        }
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    #[DataProvider('invalidProofs')]
    public function test_invalid_detected_content_or_size_is_rejected_before_storage(string $kind): void
    {
        $this->assertStorageError($this->upload($this->branchAdmin, $this->invalidProof($kind)),
            'ASSESSMENT_BILL_PROOF_CONTENT_INVALID');
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    public static function invalidProofs(): iterable
    {
        yield 'spoof' => ['spoof'];
        yield 'gif' => ['gif'];
        yield 'empty' => ['empty'];
        yield 'oversize' => ['oversize'];
    }

    public function test_replacement_is_fingerprint_fenced_and_cleans_superseded_or_stale_object(): void
    {
        $first = $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg')));
        $firstKey = (string) AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key;
        $second = $this->execute($this->upload($this->branchAdmin, $this->proof('png'), $first->proofFingerprint));
        $secondKey = (string) AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key;
        $this->assertTrue($second->replaced);
        $this->assertNotSame($firstKey, $secondKey);
        Storage::disk('payment-proofs')->assertMissing($firstKey);
        Storage::disk('payment-proofs')->assertExists($secondKey);

        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg'), $first->proofFingerprint),
            'ASSESSMENT_BILL_PROOF_CONFLICT');
        $this->assertSame($secondKey, AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());
    }

    public function test_expired_bill_rejects_initial_and_replacement_upload_at_exact_server_now(): void
    {
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['expires_at' => now()]);
        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')),
            'ASSESSMENT_BILL_PROOF_STATE_INVALID');
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');

        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['expires_at' => now()->addSecond()]);
        $first = $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg')));
        $before = AssessmentBill::query()->findOrFail($this->bill['bill'])->only([
            'proof_object_key', 'proof_checksum_sha256', 'proof_mime_type', 'proof_size_bytes', 'proof_uploaded_at',
        ]);
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['expires_at' => now()]);

        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('png'), $first->proofFingerprint),
            'ASSESSMENT_BILL_PROOF_STATE_INVALID');
        $after = AssessmentBill::query()->findOrFail($this->bill['bill']);
        $this->assertSame($before['proof_object_key'], $after->proof_object_key);
        $this->assertSame($before['proof_checksum_sha256'], $after->proof_checksum_sha256);
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());
        Storage::disk('payment-proofs')->assertExists((string) $before['proof_object_key']);
    }

    #[DataProvider('participantAuthorityRevocations')]
    public function test_participant_authority_revoked_after_storage_is_rechecked_before_bill_lookup(string $mutation): void
    {
        $this->makeSelfBill();
        $principal = new AssessmentPrincipal($this->bill['participant'], $this->bill['organization'], $this->bill['attempt']);
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, function (string $path, string $contents, array $options) use ($disk, $mutation): bool {
            $stored = $disk->put($path, $contents, $options);
            $column = $mutation === 'participant_deleted' ? 'participants' : 'assessment_participants';
            DB::table($column)->where('id', $mutation === 'participant_deleted'
                ? $this->bill['participant'] : $this->bill['attempt'])->update([
                    $mutation === 'participant_deleted' ? 'deleted_at' : 'revoked_at' => now(),
                ]);

            return $stored;
        });
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertStorageError($this->upload($principal, $this->proof('jpeg')),
            'ASSESSMENT_BILL_PROOF_NOT_FOUND');
        $billQueries = array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, 'assessment_bills')));
        $this->assertSame([], $billQueries);
        $disk->assertDirectoryEmpty('/');
        $this->assertNull(DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('proof_object_key'));
    }

    public static function participantAuthorityRevocations(): iterable
    {
        yield 'participant soft deleted' => ['participant_deleted'];
        yield 'attempt revoked' => ['attempt_revoked'];
    }

    #[DataProvider('corruptExistingProofs')]
    public function test_corrupt_existing_proof_is_rejected_without_replacement_or_old_delete(array $corruption): void
    {
        $first = $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg')));
        $bill = AssessmentBill::query()->findOrFail($this->bill['bill']);
        $oldKey = (string) $bill->proof_object_key;
        DB::table('assessment_bills')->where('id', $bill->id)->update($corruption);
        $corruptKey = (string) ($corruption['proof_object_key'] ?? $oldKey);
        $disk = Storage::disk('payment-proofs');
        $deleted = [];
        $this->proxyDisk($disk,
            fn (string $path, string $contents, array $options): bool => $disk->put($path, $contents, $options),
            function (string $path) use ($disk, &$deleted): bool {
                $deleted[] = $path;

                return $disk->delete($path);
            });

        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('png'), $first->proofFingerprint),
            'ASSESSMENT_BILL_PROOF_SCOPE_INVALID');
        $this->assertNotContains($corruptKey, $deleted);
        $this->assertNotContains($oldKey, $deleted);
        $disk->assertExists($oldKey);
        $this->assertCount(1, $disk->allFiles());
        foreach ($corruption as $column => $value) {
            $this->assertSame((string) $value,
                (string) DB::table('assessment_bills')->where('id', $bill->id)->value($column));
        }
    }

    public static function corruptExistingProofs(): iterable
    {
        yield 'key traversal' => [['proof_object_key' => '../assessment-bills/proof.pdf']];
        yield 'wrong key prefix' => [['proof_object_key' => 'other/aa/'.str_repeat('b', 62).'.pdf']];
        yield 'uppercase key' => [['proof_object_key' => 'assessment-bills/AA/'.str_repeat('b', 62).'.pdf']];
        yield 'uppercase checksum' => [['proof_checksum_sha256' => str_repeat('A', 64)]];
        yield 'foreign MIME' => [['proof_mime_type' => 'image/gif']];
        yield 'zero size' => [['proof_size_bytes' => 0]];
        yield 'oversize' => [['proof_size_bytes' => 5_120_001]];
        yield 'malformed timestamp' => [['proof_uploaded_at' => 'not-a-timestamp']];
        yield 'future timestamp' => [['proof_uploaded_at' => '2099-01-01 00:00:00']];
    }

    public function test_past_existing_upload_timestamp_remains_replaceable_with_exact_fingerprint(): void
    {
        $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg')));
        $past = now()->subDay()->toImmutable()->utc()->startOfSecond();
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['proof_uploaded_at' => $past]);
        $bill = AssessmentBill::query()->findOrFail($this->bill['bill']);
        $fingerprint = $this->proofFingerprint($bill, $past);

        $this->assertTrue($this->execute($this->upload($this->branchAdmin, $this->proof('png'), $fingerprint))->replaced);
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());
    }

    public function test_authoritative_recheck_failure_after_store_cleans_new_object(): void
    {
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, function (string $path, string $contents, array $options) use ($disk): bool {
            $stored = $disk->put($path, $contents, $options);
            DB::table('admins')->where('id', $this->branchAdmin->id)->update(['role' => AdminRole::Staff->value]);

            return $stored;
        });
        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')), 'ASSESSMENT_BILL_PROOF_NOT_FOUND');
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        $disk->assertDirectoryEmpty('/');
    }

    public function test_failed_new_object_cleanup_preserves_authoritative_error_and_database_state(): void
    {
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, function (string $path, string $contents, array $options) use ($disk): bool {
            $stored = $disk->put($path, $contents, $options);
            DB::table('admins')->where('id', $this->branchAdmin->id)->update(['role' => AdminRole::Staff->value]);

            return $stored;
        }, static fn (): bool => false);

        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')),
            'ASSESSMENT_BILL_PROOF_NOT_FOUND');
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        $this->assertCount(1, $disk->allFiles());
    }

    public function test_persist_failure_rolls_back_metadata_and_cleans_new_object(): void
    {
        AssessmentBill::updated(static function (): void {
            throw new RuntimeException('synthetic persistence crash');
        });
        try {
            $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg')));
            $this->fail('Expected persistence crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic persistence crash', $exception->getMessage());
        } finally {
            AssessmentBill::flushEventListeners();
        }
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    public function test_old_delete_failure_does_not_rollback_new_metadata(): void
    {
        $first = $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg')));
        $firstKey = (string) AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key;
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, fn (string $path, string $contents, array $options): bool => $disk->put($path, $contents, $options),
            static fn (): bool => false);
        $second = $this->execute($this->upload($this->branchAdmin, $this->proof('png'), $first->proofFingerprint));
        $secondKey = (string) AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key;
        $this->assertTrue($second->replaced);
        $this->assertNotSame($firstKey, $secondKey);
        $disk->assertExists($firstKey);
        $disk->assertExists($secondKey);
    }

    public function test_storage_failure_never_mutates_metadata(): void
    {
        $disk = Storage::disk('payment-proofs');
        $this->proxyDisk($disk, static fn (): bool => false);
        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')),
            'ASSESSMENT_BILL_PROOF_STORAGE_FAILED');
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        $disk->assertDirectoryEmpty('/');
    }

    public function test_ambient_scope_and_corrupt_bill_never_leave_partial_identity(): void
    {
        foreach ([
            ['status' => 'rejected', 'error' => 'ASSESSMENT_BILL_PROOF_STATE_INVALID'],
            ['gateway_ref' => 'provider', 'error' => 'ASSESSMENT_BILL_PROOF_CHANNEL_INVALID'],
        ] as $case) {
            $error = $case['error'];
            unset($case['error']);
            $before = (array) DB::table('assessment_bills')->where('id', $this->bill['bill'])->first();
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update($case);
            $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')), $error);
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update($before);
        }
        DB::table('assessment_participants')->where('id', $this->bill['attempt'])->update(['metadata' => '{}']);
        $this->assertStorageError($this->upload($this->branchAdmin, $this->proof('jpeg')),
            'ASSESSMENT_BILL_PROOF_SCOPE_INVALID');
        $this->assertNull(AssessmentBill::query()->findOrFail($this->bill['bill'])->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');

        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg'))));
    }

    public function test_ambient_rls_context_is_rejected_before_storage(): void
    {
        $this->expectException(LogicException::class);
        app(RlsContextRunner::class)->run(new RlsContext('service'),
            fn () => $this->execute($this->upload($this->branchAdmin, $this->proof('jpeg'))));
    }

    private function execute(AssessmentBillProofUpload $upload): object
    {
        return app(StoreAssessmentBillProof::class)->execute($upload);
    }

    private function upload(Admin|AssessmentPrincipal $uploader, UploadedFile $proof, ?string $expected = null): AssessmentBillProofUpload
    {
        return new AssessmentBillProofUpload($uploader, $this->bill['reference'], $proof, $expected);
    }

    private function assertStorageError(AssessmentBillProofUpload $upload, string $error): void
    {
        try {
            $this->execute($upload);
            $this->fail('Expected fail closed.');
        } catch (\DomainException $exception) {
            $this->assertSame($error, $exception->getMessage());
        }
    }

    private function pendingManualBill(): array
    {
        $f = AssessmentAccessFixture::create();
        DB::table('assessment_participants')->where('id', $f['attempt'])->update([
            'assessment_status' => 'PROVISIONED', 'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION'], JSON_THROW_ON_ERROR)]);
        DB::table('assessment_entitlements')->where('id', $f['entitlement'])->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $f['item'])->update(['settled_at' => null]);
        $method = (int) DB::table('assessment_bills')->where('id', $f['bill'])->value('payment_method_id');
        DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer', 'is_active' => false]);
        DB::table('assessment_bills')->where('id', $f['bill'])->update([
            'status' => 'pending', 'paid_at' => null, 'verified_at' => null, 'verified_by_admin_id' => null,
            'rejection_reason' => null, 'gateway_ref' => null, 'invoice_url' => null, 'proof_object_key' => null,
            'proof_checksum_sha256' => null, 'proof_mime_type' => null, 'proof_size_bytes' => null,
            'proof_uploaded_at' => null, 'expires_at' => now()->addHour()]);

        return [...$f, 'method' => $method, 'reference' => DB::table('assessment_bills')->where('id', $f['bill'])->value('public_reference')];
    }

    private function makeSelfBill(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::table('assessment_participants')->where('id', $this->bill['attempt'])->update(['funding_mode' => 'COMMERCIAL_SELF_PAY',
                'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR)]);
            DB::table('assessment_charges')->where('id', $this->bill['charge'])->update(['payer_type' => 'self']);
            DB::table('assessment_bill_items')->where('id', $this->bill['item'])->update(['payer_type' => 'self',
                'payer_participant_id' => $this->bill['participant']]);
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['payer_type' => 'self',
                'payer_participant_id' => $this->bill['participant']]);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    private function makeOrganizationBill(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::table('assessment_participants')->where('id', $this->bill['attempt'])->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION'], JSON_THROW_ON_ERROR)]);
            DB::table('assessment_charges')->where('id', $this->bill['charge'])->update(['payer_type' => 'organization']);
            DB::table('assessment_bill_items')->where('id', $this->bill['item'])->update(['payer_type' => 'organization', 'payer_participant_id' => null]);
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['payer_type' => 'organization', 'payer_participant_id' => null]);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    private function clearProof(): void
    {
        $bill = AssessmentBill::query()->findOrFail($this->bill['bill']);
        Storage::disk('payment-proofs')->delete((string) $bill->proof_object_key);
        $bill->update(['proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
            'proof_size_bytes' => null, 'proof_uploaded_at' => null]);
    }

    private function admin(AdminRole $role, int $branchId, bool $flag = false): Admin
    {
        return Admin::query()->create(['name' => 'Synthetic uploader', 'email' => uniqid().'@example.test', 'password' => 'not-real',
            'role' => $role, 'branch_id' => in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true) ? $branchId : null,
            'can_verify_payments' => $flag]);
    }

    private function proof(string $type): UploadedFile
    {
        return match ($type) {
            'jpeg' => UploadedFile::fake()->image('candidate-name.jpg', 800, 800),
            'png' => UploadedFile::fake()->image('candidate-name.png', 800, 800),
            'pdf' => UploadedFile::fake()->createWithContent('candidate-name.pdf', "%PDF-1.4\nsynthetic\n%%EOF"),
            'max' => $this->sizedJpeg('candidate-name.jpg', 5_120_000),
        };
    }

    private function invalidProof(string $type): UploadedFile
    {
        return match ($type) {
            'spoof' => UploadedFile::fake()->createWithContent('proof.jpg', '<?php echo "unsafe";'),
            'gif' => UploadedFile::fake()->image('proof.gif', 800, 800),
            'empty' => UploadedFile::fake()->createWithContent('proof.pdf', ''),
            'oversize' => $this->sizedJpeg('proof.jpg', 5_120_001),
        };
    }

    private function sizedJpeg(string $filename, int $bytes): UploadedFile
    {
        $image = UploadedFile::fake()->image('seed.jpg', 8, 8);
        $contents = file_get_contents((string) $image->getRealPath());
        if (! is_string($contents) || strlen($contents) > $bytes) {
            throw new RuntimeException('Unable to create synthetic proof fixture.');
        }

        return UploadedFile::fake()->createWithContent($filename,
            $contents.str_repeat("\0", $bytes - strlen($contents)));
    }

    private function proxyDisk(FilesystemAdapter $real, callable $put, ?callable $delete = null): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('put')->andReturnUsing($put);
        $mock->shouldReceive('delete')->andReturnUsing($delete ?? fn (string $path): bool => $real->delete($path));
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
    }

    private function proofFingerprint(AssessmentBill $bill, \DateTimeInterface $uploadedAt): string
    {
        return hash('sha256', implode("\0", [
            $bill->proof_object_key, $bill->proof_checksum_sha256, $bill->proof_mime_type,
            (string) $bill->proof_size_bytes,
            $uploadedAt->format('Y-m-d\TH:i:s.u\Z'),
        ]));
    }
}
