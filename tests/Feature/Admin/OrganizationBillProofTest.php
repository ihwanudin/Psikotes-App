<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Payments\StoreAssessmentBillProof;
use App\Data\Payments\AssessmentBillProofUpload;
use App\Enums\AdminRole;
use App\Filament\Resources\OrganizationBills\Pages\ViewOrganizationBill;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Services\Payments\AssessmentBillProofIdentity;
use App\Services\Payments\OrganizationBillProofUrlIssuer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class OrganizationBillProofTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $fixture;

    private Admin $admin;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->freezeTime();
        Storage::fake('payment-proofs');
        $this->fixture = $this->pendingManualOrganizationBill();
        $this->admin = $this->admin(AdminRole::BranchAdmin, $this->fixture['organization']);
        $this->actingAs($this->admin, 'admin');
    }

    public function test_branch_page_uploads_and_replaces_canonical_private_proof_without_marking_paid(): void
    {
        $page = Livewire::test(ViewOrganizationBill::class, ['record' => $this->fixture['bill']])
            ->assertActionVisible('uploadProof')->assertActionHidden('openProof')
            ->callAction('uploadProof', ['proof' => UploadedFile::fake()->image('first.jpg', 800, 800)])
            ->assertHasNoActionErrors()->assertActionVisible('openProof');

        $bill = AssessmentBill::query()->findOrFail($this->fixture['bill']);
        $first = (string) $bill->proof_object_key;
        $this->assertSame('pending', $bill->status);
        $this->assertNull($bill->paid_at);
        Storage::disk('payment-proofs')->assertExists($first);
        $this->assertStringNotContainsString($first, $page->html());
        $this->assertStringNotContainsString((string) $bill->proof_checksum_sha256, $page->html());
        $page->assertSee('JPEG')->assertSee('Menunggu pembayaran');
        $this->assertSame($bill->proof_size_bytes, $page->instance()->proofSummary['size']);
        $page->callAction('uploadProof', ['proof' => UploadedFile::fake()->image('second.png', 800, 800)])
            ->assertHasNoActionErrors();
        $second = (string) $bill->fresh()->proof_object_key;
        $this->assertNotSame($first, $second);
        Storage::disk('payment-proofs')->assertMissing($first);
        Storage::disk('payment-proofs')->assertExists($second);
        $this->assertDatabaseHas('assessment_entitlements', [
            'id' => $this->fixture['entitlement'], 'status' => 'locked', 'ready_at' => null,
        ]);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_upload_revalidates_stale_page_role_tenant_and_proof_fingerprint(): void
    {
        $receipt = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
            $this->admin,
            $this->fixture['reference'],
            UploadedFile::fake()->image('external.jpg', 800, 800),
            null,
        ));
        try {
            app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
                $this->admin,
                $this->fixture['reference'],
                UploadedFile::fake()->image('stale.jpg', 800, 800),
                null,
            ));
            $this->fail('Stale proof fingerprint must fail.');
        } catch (\DomainException $exception) {
            $this->assertSame('ASSESSMENT_BILL_PROOF_CONFLICT', $exception->getMessage());
        }
        $this->assertSame($receipt->proofFingerprint,
            app(AssessmentBillProofIdentity::class)
                ->fingerprint(AssessmentBill::findOrFail($this->fixture['bill']), now()));
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());

        DB::table('admins')->where('id', $this->admin->id)->update(['role' => AdminRole::Staff->value]);
        try {
            app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
                $this->admin,
                $this->fixture['reference'],
                UploadedFile::fake()->image('revoked.jpg', 800, 800),
                $receipt->proofFingerprint,
            ));
            $this->fail('Revoked role must fail.');
        } catch (\DomainException $exception) {
            $this->assertSame('ASSESSMENT_BILL_PROOF_NOT_FOUND', $exception->getMessage());
        }
        $this->assertCount(1, Storage::disk('payment-proofs')->allFiles());
    }

    public function test_terminal_and_nonmanual_bills_never_accept_upload(): void
    {
        foreach ([['status' => 'paid'], ['status' => 'rejected'], ['status' => 'expired']] as $change) {
            DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update($change);
            Livewire::test(ViewOrganizationBill::class, ['record' => $this->fixture['bill']])
                ->assertActionHidden('uploadProof');
        }
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['status' => 'pending']);
        DB::table('payment_methods')->where('id', $this->fixture['method'])->update(['code' => 'xendit']);
        Livewire::test(ViewOrganizationBill::class, ['record' => $this->fixture['bill']])
            ->assertActionHidden('uploadProof');
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    public function test_private_access_rechecks_branch_actor_and_current_fingerprint_and_audits_safely(): void
    {
        $receipt = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
            $this->admin, $this->fixture['reference'], UploadedFile::fake()->image('proof.jpg'), null,
        ));
        Storage::disk('payment-proofs')->buildTemporaryUrlsUsing(
            fn (string $path): string => 'https://private.example.test/synthetic-token',
        );
        $access = app(OrganizationBillProofUrlIssuer::class)
            ->issue($this->admin, $this->fixture['reference'], $receipt->proofFingerprint);
        $this->assertSame('https://private.example.test/synthetic-token', $access->url);
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.branch_proof_temporary_url_issued')->sole();
        $this->assertStringNotContainsString('assessment-bills/', (string) $audit->context);
        $this->assertStringNotContainsString('private.example', (string) $audit->context);

        DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $this->fixture['foreignOrganization']]);
        $this->expectException(\DomainException::class);
        app(OrganizationBillProofUrlIssuer::class)
            ->issue($this->admin, $this->fixture['reference'], $receipt->proofFingerprint);
    }

    public function test_access_rejects_replacement_during_url_generation_without_audit(): void
    {
        $receipt = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
            $this->admin, $this->fixture['reference'], UploadedFile::fake()->image('proof.jpg'), null,
        ));
        DB::table('audit_logs')->delete();
        $disk = Storage::disk('payment-proofs');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->andReturnUsing(fn (string $key): bool => $disk->exists($key));
        $mock->shouldReceive('temporaryUrl')->andReturnUsing(function (): string {
            DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update([
                'proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
                'proof_size_bytes' => null, 'proof_uploaded_at' => null,
            ]);

            return 'https://private.example.test/stale';
        });
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
        try {
            app(OrganizationBillProofUrlIssuer::class)
                ->issue($this->admin, $this->fixture['reference'], $receipt->proofFingerprint);
            $this->fail('Stale proof access must fail.');
        } catch (\DomainException $exception) {
            $this->assertSame('ORGANIZATION_BILL_PROOF_NOT_FOUND', $exception->getMessage());
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_wrong_role_deleted_and_cross_tenant_actors_cannot_probe_proof(): void
    {
        $receipt = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
            $this->admin, $this->fixture['reference'], UploadedFile::fake()->image('proof.jpg'), null,
        ));
        Storage::disk('payment-proofs')->buildTemporaryUrlsUsing(
            fn (): string => 'https://private.example.test/never-for-denial',
        );
        $actors = [
            $this->admin(AdminRole::Staff, $this->fixture['organization']),
            $this->admin(AdminRole::BranchAdmin, $this->fixture['foreignOrganization']),
        ];
        $deleted = $this->admin(AdminRole::BranchAdmin, $this->fixture['organization']);
        $deleted->delete();
        $actors[] = $deleted;

        foreach ($actors as $actor) {
            try {
                app(OrganizationBillProofUrlIssuer::class)
                    ->issue($actor, $this->fixture['reference'], $receipt->proofFingerprint);
                $this->fail('Unauthorized actor must fail closed.');
            } catch (\DomainException $exception) {
                $this->assertSame('ORGANIZATION_BILL_PROOF_NOT_FOUND', $exception->getMessage());
            }
        }
        $this->assertSame(0, DB::table('audit_logs')
            ->where('action', 'assessment_bill.branch_proof_temporary_url_issued')->count());
    }

    private function pendingManualOrganizationBill(): array
    {
        $f = AssessmentAccessFixture::create();
        $foreign = AssessmentAccessFixture::create();
        DB::table('assessment_participants')->where('id', $f['attempt'])->update([
            'assessment_status' => 'PROVISIONED', 'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_entitlements')->where('id', $f['entitlement'])->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $f['item'])->update(['settled_at' => null]);
        $method = (int) DB::table('assessment_bills')->where('id', $f['bill'])->value('payment_method_id');
        DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer', 'is_active' => false]);
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::table('assessment_charges')->where('id', $f['charge'])->update(['payer_type' => 'organization']);
            DB::table('assessment_bill_items')->where('id', $f['item'])->update(['payer_type' => 'organization', 'payer_participant_id' => null]);
            DB::table('assessment_bills')->where('id', $f['bill'])->update([
                'payer_type' => 'organization', 'payer_participant_id' => null, 'status' => 'pending',
                'paid_at' => null, 'verified_at' => null, 'verified_by_admin_id' => null,
                'rejection_reason' => null, 'gateway_ref' => null, 'invoice_url' => null,
                'proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
                'proof_size_bytes' => null, 'proof_uploaded_at' => null, 'expires_at' => now()->addHour(),
            ]);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        return [...$f, 'method' => $method,
            'reference' => DB::table('assessment_bills')->where('id', $f['bill'])->value('public_reference'),
            'foreignOrganization' => $foreign['organization']];
    }

    private function admin(AdminRole $role, int $branch): Admin
    {
        return Admin::query()->create(['name' => 'Branch proof uploader', 'email' => uniqid().'@example.test',
            'password' => 'not-real', 'role' => $role, 'branch_id' => $branch, 'can_verify_payments' => false]);
    }
}
