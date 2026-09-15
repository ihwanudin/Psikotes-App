<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Actions\CreateCollectiveBillAction;
use App\Filament\Resources\AssessmentParticipants\Pages\CreateCollectiveBill;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\TestPackage;
use App\Services\Payments\AssessmentPriceSnapshot;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class CollectiveBillSelectionTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $own;

    private Admin $admin;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->own = Fixture::create();
        DB::table('package_items')->insert([
            'package_id' => $this->own['package'], 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
        $this->admin = Admin::create(['branch_id' => $this->own['organization'], 'name' => 'Admin cabang sintetis',
            'email' => 'collective-core@example.test', 'password' => 'synthetic-only', 'role' => AdminRole::BranchAdmin]);
        $this->actingAs($this->admin, 'admin');
        $this->method = DB::table('payment_methods')->insertGetId([
            'code' => 'manual_transfer', 'display_name' => 'Transfer sintetis', 'is_active' => true,
        ]);
    }

    public function test_preview_then_confirmation_delegates_to_one_canonical_bill_and_replays(): void
    {
        $other = Fixture::create(['organization' => $this->own['organization']]);
        DB::table('package_items')->insert([
            'package_id' => $other['package'], 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
        $selection = [Fixture::selection($this->own, true), Fixture::selection($other)];
        $action = app(CreateCollectiveBillAction::class);
        $preview = $action->preview($selection);
        $bill = $action->confirm($selection, $this->method, $preview['selectionHash']);
        $replay = $action->confirm(array_reverse($selection), $this->method, $preview['selectionHash']);

        $this->assertSame($bill->id, $replay->id);
        $this->assertSame(230, $bill->amount);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_bill_items', 2);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
        foreach (['assessment_entitlements', 'orders', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_filament_page_requires_explicit_preview_before_confirmation(): void
    {
        Livewire::test(CreateCollectiveBill::class)
            ->assertSuccessful()
            ->assertSee('Buat tagihan kolektif')
            ->call('confirm')
            ->assertHasErrors(['selected']);
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    public function test_livewire_checkbox_preview_confirm_and_redirect_use_canonical_bill(): void
    {
        $component = Livewire::test(CreateCollectiveBill::class)
            ->set('selected', [(string) $this->own['attempt']])
            ->set('consultation.'.$this->own['attempt'], true)
            ->set('paymentMethodId', $this->method)
            ->call('review')
            ->assertSet('preview.totalAmount', 130)
            ->call('confirm');
        $bill = AssessmentBill::query()->sole();
        $component->assertRedirect(OrganizationBillResource::getUrl('view', ['record' => $bill]));
        $this->assertSame(130, $bill->amount);
        $this->assertDatabaseCount('assessment_bills', 1);
    }

    public function test_livewire_selection_change_clears_preview_and_blocks_confirmation(): void
    {
        Livewire::test(CreateCollectiveBill::class)
            ->set('selected', [(string) $this->own['attempt']])
            ->call('review')->assertNotSet('preview', null)
            ->set('consultation.'.$this->own['attempt'], true)->assertSet('preview', null)
            ->call('confirm')->assertHasErrors(['selected']);
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    #[DataProvider('confirmRevocations')]
    public function test_persisted_membership_is_reauthorized_after_preview_before_confirm(string $case): void
    {
        $selection = [Fixture::selection($this->own)];
        $preview = app(CreateCollectiveBillAction::class)->preview($selection);
        match ($case) {
            'role' => DB::table('admins')->where('id', $this->admin->id)->update(['role' => AdminRole::Staff->value]),
            'branch' => DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => Fixture::create()['organization']]),
            'deleted' => DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]),
        };
        $this->expectException($case === 'branch' ? DomainException::class : AuthorizationException::class);
        try {
            app(CreateCollectiveBillAction::class)->confirm($selection, $this->method, $preview['selectionHash']);
        } finally {
            $this->assertDatabaseCount('assessment_bills', 0);
            $this->assertDatabaseCount('assessment_charges', 0);
        }
    }

    public static function confirmRevocations(): iterable
    {
        foreach (['role', 'branch', 'deleted'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_unexpected_writer_failure_propagates_and_transaction_rolls_back(): void
    {
        $component = Livewire::test(CreateCollectiveBill::class)
            ->set('selected', [(string) $this->own['attempt']])
            ->set('paymentMethodId', $this->method)
            ->call('review');
        Event::listen('eloquent.creating: '.AssessmentBill::class, fn () => throw new \RuntimeException('synthetic-writer-failure'));
        try {
            $component->call('confirm');
            $this->fail('Unexpected writer failure was disguised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic-writer-failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AssessmentBill::class);
        }
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_price_or_status_change_after_preview_fails_closed_without_partial_bill(): void
    {
        $selection = [Fixture::selection($this->own)];
        $preview = app(CreateCollectiveBillAction::class)->preview($selection);
        DB::table('packages')->where('id', $this->own['package'])->update(['amount' => 999]);
        try {
            app(CreateCollectiveBillAction::class)->confirm($selection, $this->method, $preview['selectionHash']);
            $this->fail('Stale preview accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('PREVIEW_CHANGED', $exception->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
    }

    #[DataProvider('disabledCases')]
    public function test_ineligible_rows_are_disabled_with_generic_reason(string $case): void
    {
        match ($case) {
            'legacy' => DB::table('assessment_participants')->where('id', $this->own['attempt'])->update(['metadata' => null]),
            'status' => DB::table('assessment_participants')->where('id', $this->own['attempt'])->update(['assessment_status' => 'READY']),
            'free' => DB::table('packages')->where('id', $this->own['package'])->update(['amount' => 0]),
            'self' => DB::table('integration_sources')->where('id', $this->own['source'])->update(['allowed_payer_types' => '["self"]']),
            'claimed' => $this->claimOwnAttempt(),
        };
        $choice = collect(app(CreateCollectiveBillAction::class)->choices())->firstWhere('assessmentParticipantId', $this->own['attempt']);
        $this->assertFalse($choice['enabled']);
        $this->assertSame('Tidak tersedia untuk tagihan kolektif.', $choice['disabledReason']);
    }

    public static function disabledCases(): iterable
    {
        foreach (['legacy', 'status', 'free', 'self', 'claimed'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('deniedCases')]
    public function test_role_membership_and_environment_are_reloaded_for_every_boundary(string $case): void
    {
        match ($case) {
            'role' => DB::table('admins')->where('id', $this->admin->id)->update(['role' => AdminRole::Staff->value]),
            'tenant' => DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => Fixture::create()['organization']]),
            'deleted' => DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]),
            'production' => app()->instance('env', 'production'),
        };
        try {
            $this->expectException($case === 'tenant' ? DomainException::class : AuthorizationException::class);
            app(CreateCollectiveBillAction::class)->preview([Fixture::selection($this->own)]);
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public static function deniedCases(): iterable
    {
        foreach (['role', 'tenant', 'deleted', 'production'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_foreign_duplicate_free_and_tampered_hash_are_rejected_without_writes(): void
    {
        $foreign = Fixture::create();
        foreach ([[Fixture::selection($foreign)], [Fixture::selection($this->own), Fixture::selection($this->own)]] as $selection) {
            try {
                app(CreateCollectiveBillAction::class)->preview($selection);
                $this->fail('Invalid selection accepted.');
            } catch (DomainException|\InvalidArgumentException) {
            }
        }
        try {
            app(CreateCollectiveBillAction::class)->confirm([Fixture::selection($this->own)], $this->method, str_repeat('a', 64));
            $this->fail('Tampered preview hash accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('PREVIEW_CHANGED', $exception->getMessage());
        }
        $free = Fixture::create(['organization' => $this->own['organization']], 0);
        $this->expectException(DomainException::class);
        try {
            app(CreateCollectiveBillAction::class)->preview([Fixture::selection($free)]);
        } finally {
            $this->assertDatabaseCount('assessment_bills', 0);
            $this->assertDatabaseCount('assessment_charges', 0);
        }
    }

    private function claimOwnAttempt(): void
    {
        $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->own['package']), false);
        $charge = DB::table('assessment_charges')->insertGetId(['assessment_participant_id' => $this->own['attempt'],
            'organization_id' => $this->own['organization'], 'participant_id' => $this->own['participant'], 'package_id' => $this->own['package'],
            'payer_type' => 'organization', 'base_amount' => 100, 'consultation_amount' => 0, 'consultation_requested' => false,
            'amount' => 100, 'currency' => 'IDR', 'price_snapshot' => json_encode($snapshot), 'policy_snapshot' => '{}',
            'created_at' => now(), 'updated_at' => now()]);
        $bill = DB::table('assessment_bills')->insertGetId(['organization_id' => $this->own['organization'], 'payer_type' => 'organization',
            'public_reference' => 'AB_'.str_repeat('A', 26), 'amount' => 100, 'currency' => 'IDR', 'item_count' => 1,
            'selection_hash' => str_repeat('a', 64), 'idempotency_key' => 'claimed', 'request_hash' => str_repeat('b', 64),
            'status' => 'reserved', 'payment_method_id' => $this->method, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assessment_bill_items')->insert(['bill_id' => $bill, 'charge_id' => $charge, 'organization_id' => $this->own['organization'],
            'participant_id' => $this->own['participant'], 'payer_type' => 'organization', 'amount' => 100, 'currency' => 'IDR',
            'created_at' => now(), 'updated_at' => now()]);
    }
}
