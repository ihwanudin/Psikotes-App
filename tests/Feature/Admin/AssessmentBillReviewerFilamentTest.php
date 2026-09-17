<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\AssessmentBillReviews\AssessmentBillReviewResource;
use App\Filament\Resources\AssessmentBillReviews\Pages\ListAssessmentBillReviews;
use App\Filament\Resources\AssessmentBillReviews\Pages\ViewAssessmentBillReview;
use App\Models\Admin;
use App\Models\AssessmentBill;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillReviewerFilamentTest extends OrganizationPaymentTestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->bill = $this->manualBillWithProof();
        $this->reviewer = $this->admin(AdminRole::SuperAdmin, null, false);
    }

    public function test_testing_only_resource_lists_and_filters_safe_manual_review_records(): void
    {
        $second = $this->manualBillWithProof('cc');
        DB::table('assessment_bills')->where('id', $second['bill'])->update([
            'status' => 'rejected', 'verified_at' => now(), 'rejection_reason' => 'UNREADABLE_PROOF',
        ]);
        $missingProof = $this->manualBillWithProof('dd');
        DB::table('assessment_bills')->where('id', $missingProof['bill'])->update([
            'proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
            'proof_size_bytes' => null, 'proof_uploaded_at' => null,
        ]);

        $this->actingAs($this->reviewer, 'admin');

        $component = Livewire::test(ListAssessmentBillReviews::class)
            ->assertCanSeeTableRecords([
                AssessmentBill::query()->findOrFail($this->bill['bill']),
                AssessmentBill::query()->findOrFail($second['bill']),
            ])
            ->assertCanNotSeeTableRecords([AssessmentBill::query()->findOrFail($missingProof['bill'])])
            ->filterTable('status', 'pending')
            ->assertCanSeeTableRecords([AssessmentBill::query()->findOrFail($this->bill['bill'])])
            ->assertCanNotSeeTableRecords([AssessmentBill::query()->findOrFail($second['bill'])]);

        $payload = json_encode($component->instance(), JSON_THROW_ON_ERROR);
        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }
        $this->assertTrue(AssessmentBillReviewResource::isDiscovered());
        $this->assertTrue(AssessmentBillReviewResource::shouldRegisterNavigation());
        $this->assertSame([10, 25], $component->instance()->getTable()->getPaginationPageOptions());
    }

    public function test_detail_renders_only_safe_fields_and_has_only_bounded_review_actions(): void
    {
        $this->actingAs($this->reviewer, 'admin');

        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
            ->assertSee($this->bill['reference'])
            ->assertSee('Synthetic Organization')
            ->assertSee('Buka bukti')
            ->assertDontSee('Synthetic Candidate')
            ->assertDontSee($this->bill['proofKey'])
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('upload')
            ->assertActionDoesNotExist('delete');

        $payload = json_encode($component->instance(), JSON_THROW_ON_ERROR);
        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }
    }

    public function test_direct_urls_render_safe_detail_and_hide_unauthorized_records(): void
    {
        $index = AssessmentBillReviewResource::getUrl('index');
        $detail = AssessmentBillReviewResource::getUrl('view', ['record' => $this->bill['bill']]);
        $this->actingAs($this->reviewer, 'admin');

        $this->get($index)->assertOk();
        $this->get($detail)
            ->assertOk()
            ->assertSee($this->bill['reference'])
            ->assertSee('Synthetic Organization')
            ->assertDontSee('Synthetic Candidate')
            ->assertDontSee($this->bill['proofKey'])
            ->assertDontSee(str_repeat('e', 64));

    }

    public function test_unauthorized_direct_urls_do_not_reveal_reviewer_records(): void
    {
        $branchAdmin = $this->admin(AdminRole::BranchAdmin, $this->bill['organization'], true);
        $this->actingAs($branchAdmin, 'admin');

        $this->get(AssessmentBillReviewResource::getUrl('index'))->assertForbidden();
        $this->get(AssessmentBillReviewResource::getUrl('view', ['record' => $this->bill['bill']]))
            ->assertNotFound();
    }

    public function test_list_query_is_bounded_and_never_projects_proof_or_participant_fields(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->actingAs($this->reviewer, 'admin');

        Livewire::test(ListAssessmentBillReviews::class)
            ->assertCanSeeTableRecords([AssessmentBill::query()->findOrFail($this->bill['bill'])]);

        $billQueries = array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, 'from "assessment_bills"')));
        $this->assertNotEmpty($billQueries);
        $this->assertLessThanOrEqual(4, count($billQueries));
        foreach ($billQueries as $sql) {
            $projection = strstr($sql, ' from ', true);
            if ($projection === false) {
                continue;
            }
            foreach (['proof_object_key', 'proof_checksum_sha256', 'proof_mime_type',
                'proof_size_bytes', 'participant_id', 'gateway_ref', 'invoice_url'] as $column) {
                $this->assertStringNotContainsString($column, $projection);
            }
        }
    }

    public function test_open_proof_uses_private_issuer_and_redirects_without_persisting_secret_state(): void
    {
        $disk = Storage::disk('payment-proofs');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->once()->andReturnUsing(fn (string $key): bool => $disk->exists($key));
        $mock->shouldReceive('temporaryUrl')->once()->andReturn('https://proof.example.test/opaque-token');
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
        $this->actingAs($this->reviewer, 'admin');

        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
            ->callAction(TestAction::make('openProof'))
            ->assertRedirect('https://proof.example.test/opaque-token');

        $payload = json_encode($component->instance(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('opaque-token', $payload);
        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin', 'actor_id' => (string) $this->reviewer->id,
            'action' => 'assessment_bill.proof_temporary_url_issued',
            'subject_id' => (string) $this->bill['bill'],
        ]);
    }

    public function test_storage_failure_is_generic_and_does_not_leak_driver_details(): void
    {
        $disk = Storage::disk('payment-proofs');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->andReturnUsing(fn (string $key): bool => $disk->exists($key));
        $mock->shouldReceive('temporaryUrl')->andThrow(new RuntimeException('credential C:\\secret'));
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
        $this->actingAs($this->reviewer, 'admin');

        Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
            ->callAction(TestAction::make('openProof'))
            ->assertNotified('Bukti tidak dapat dibuka.');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_guest_roles_and_stale_super_admin_cannot_list_or_open_direct_detail(): void
    {
        Livewire::test(ListAssessmentBillReviews::class)->assertForbidden();
        Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])->assertNotFound();

        foreach ([AdminRole::BranchAdmin, AdminRole::Staff, AdminRole::Psychologist] as $role) {
            $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
                ? $this->bill['organization'] : null;
            $actor = $this->admin($role, $branch, true);
            $this->actingAs($actor, 'admin');
            Livewire::test(ListAssessmentBillReviews::class)->assertForbidden();
            Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])->assertNotFound();
        }

        DB::table('admins')->where('id', $this->reviewer->id)->update(['role' => AdminRole::Staff->value]);
        $this->actingAs($this->reviewer, 'admin');
        Livewire::test(ListAssessmentBillReviews::class)->assertForbidden();
        Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])->assertNotFound();

        $deleted = $this->admin(AdminRole::SuperAdmin, null, false);
        $deleted->delete();
        $this->actingAs($deleted, 'admin');
        Livewire::test(ListAssessmentBillReviews::class)->assertForbidden();
        Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])->assertNotFound();
    }

    public function test_reviewer_revoked_after_mount_cannot_execute_open_action(): void
    {
        $this->actingAs($this->reviewer, 'admin');
        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']]);
        DB::table('admins')->where('id', $this->reviewer->id)->update(['role' => AdminRole::Staff->value]);

        $component->call('mountAction', 'openProof')->assertNotFound();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_resource_is_not_discovered_or_in_navigation_outside_testing(): void
    {
        $this->actingAs($this->reviewer, 'admin');
        app()->detectEnvironment(static fn (): string => 'production');

        $this->assertFalse(AssessmentBillReviewResource::isDiscovered());
        $this->assertFalse(AssessmentBillReviewResource::shouldRegisterNavigation());
    }

    /** @return list<string> */
    private function sensitiveValues(): array
    {
        return [
            $this->bill['proofKey'],
            str_repeat('e', 64),
            'Synthetic Candidate',
            'PRIVATE-CANDIDATE-IDENTIFIER',
        ];
    }

    private function manualBillWithProof(string $shard = 'aa'): array
    {
        $fixture = AssessmentAccessFixture::create();
        DB::table('branches')->where('id', $fixture['organization'])->update(['name' => 'Synthetic Organization']);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Synthetic Candidate']);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])
            ->update(['external_candidate_id' => 'PRIVATE-CANDIDATE-IDENTIFIER']);
        $method = DB::table('payment_methods')->where('code', 'manual_transfer')->value('id');
        if (! is_int($method)) {
            $method = DB::table('payment_methods')->insertGetId([
                'code' => 'manual_transfer', 'display_name' => 'Transfer Manual', 'is_active' => false,
            ]);
        }
        $contents = "%PDF-1.4\nprivate proof\n%%EOF";
        $key = "assessment-bills/{$shard}/".str_repeat('f', 62).'.pdf';
        Storage::disk('payment-proofs')->put($key, $contents, ['visibility' => 'private']);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'payment_method_id' => $method,
            'status' => 'pending', 'paid_at' => null, 'gateway_ref' => null, 'invoice_url' => null,
            'proof_object_key' => $key, 'proof_checksum_sha256' => str_repeat('e', 64),
            'proof_mime_type' => 'application/pdf', 'proof_size_bytes' => strlen($contents),
            'proof_uploaded_at' => now()->subMinute(), 'verified_at' => null,
            'verified_by_admin_id' => null, 'rejection_reason' => null,
        ]);

        return [...$fixture, 'method' => $method, 'proofKey' => $key,
            'reference' => (string) DB::table('assessment_bills')->where('id', $fixture['bill'])
                ->value('public_reference')];
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
