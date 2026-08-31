<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;
use Tests\Support\CollectiveBillPreviewComponent as Component;

final class CollectiveBillPreviewComponentTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixtures;

    private array $foreign;

    private Admin $admin;

    private array $effectsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->fixtures = [];
        $packages = [];
        foreach (['A', 'A', 'B', 'B', 'C', 'C', 'A', 'B', 'C', 'A'] as $index => $group) {
            $fixture = Fixture::create($index === 0 ? null : ['organization' => $this->fixtures[0]['organization']]);
            if (! isset($packages[$group])) {
                $packages[$group] = $fixture['package'];
                DB::table('packages')->where('id', $fixture['package'])->update([
                    'code' => 'SYN-'.$group, 'name' => 'Paket '.$group,
                    'amount' => ['A' => 100, 'B' => 200, 'C' => 0][$group], 'consultation_amount' => $group === 'B' ? 50 : 30]);
            }
            DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'package_id' => $packages[$group], 'external_candidate_id' => 'KANDIDAT-'.$index,
                'assessment_attempt_id' => 'ATTEMPT-'.$index, 'assessment_round_id' => 'Periode sintetis',
                'metadata' => '{"checkout_contract_version":"checkout-v2","private":"PRIVATE-SENTINEL"}']);
            DB::table('integration_sources')->where('id', $fixture['source'])->update([
                'allowed_assessment_packages' => json_encode(['SYN-'.$group], JSON_THROW_ON_ERROR)]);
            DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Peserta sintetis '.$index]);
            $this->fixtures[] = $fixture;
        }
        $this->foreign = Fixture::create();
        DB::table('participants')->where('id', $this->foreign['participant'])->update(['full_name' => 'FOREIGN-PRIVATE-SENTINEL']);
        $this->admin = Admin::create(['name' => 'Synthetic admin', 'email' => 'component@example.test',
            'password' => 'synthetic-password', 'role' => AdminRole::BranchAdmin,
            'branch_id' => $this->fixtures[0]['organization'], 'can_verify_payments' => true]);
        $this->actingAs($this->admin, 'admin');
        $this->effectsBefore = $this->effectRows();
        Livewire::component('collective-preview-test', Component::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->effectsBefore)) {
            $this->assertSame($this->effectsBefore, $this->effectRows());
        }
        app()->instance('env', 'testing');
        parent::tearDown();
    }

    public function test_all_choices_render_and_ten_mixed_items_show_only_server_preview(): void
    {
        $component = $this->harness()->assertSet('selection', [])->assertSet('preview', null)
            ->assertSee('Tinjau')->assertDontSee('Hasil tinjauan sementara');
        foreach (range(0, 9) as $index) {
            $component->assertSee('Peserta sintetis '.$index)->assertSee('ATTEMPT-'.$index);
        }
        $component->set('selection', $this->mixedSelection())->call('review')->assertHasNoErrors()
            ->assertSet('preview.totalAmount', 1140)->assertSet('preview.paidCount', 8)->assertSet('preview.freeCount', 2)
            ->assertSee('IDR 1.140')->assertSee('Berbiaya: 8')->assertSee('Gratis: 2')
            ->assertSee('Paket B')->assertSee('IDR 250')->assertSee('Hasil tinjauan sementara')
            ->assertDontSee('PRIVATE-SENTINEL');
        $this->assertCount(10, $component->get('preview')['items']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $component->get('preview')['selectionHash']);
        $html = $component->html();
        $this->assertSame(1, preg_match_all('/<button\b/', $html));
        $this->assertStringContainsString('wire:submit="review"', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('wire:click="reserve', $html);
        $this->assertStringNotContainsString('wire:submit="confirm', $html);
    }

    public function test_checkbox_selection_and_consultation_changes_discard_old_result_and_hash(): void
    {
        $id = $this->fixtures[0]['attempt'];
        $component = $this->harness()->call('toggleAttempt', $id)
            ->assertSet('selection', [Fixture::selection($this->fixtures[0])])
            ->call('review')->assertSet('preview.totalAmount', 100);
        $oldHash = $component->get('preview')['selectionHash'];
        $component->set('selection.0.consultationRequested', true)->assertSet('preview', null)
            ->assertDontSee('Hasil tinjauan sementara')->call('review')->assertSet('preview.totalAmount', 130);
        $this->assertNotSame($oldHash, $component->get('preview')['selectionHash']);
        $component->call('toggleAttempt', $id)->assertSet('selection', [])->assertSet('preview', null)
            ->call('review')->assertHasErrors('selection')->assertDontSee('Hasil tinjauan sementara');
    }

    public function test_refresh_discards_prior_preview_and_new_review_reloads_price_and_source_policy(): void
    {
        $component = $this->harness()->set('selection', [Fixture::selection($this->fixtures[0])])
            ->call('review')->assertSet('preview.totalAmount', 100);
        DB::table('packages')->where('id', $this->fixtures[0]['package'])->update(['amount' => 777]);
        $component->call('$refresh')->assertSet('preview', null)->assertDontSee('Hasil tinjauan sementara')
            ->call('review')->assertSet('preview.totalAmount', 777)->assertSee('IDR 777');
        DB::table('integration_sources')->where('id', $this->fixtures[0]['source'])->update(['allowed_payer_types' => null]);
        $component->call('review')->assertSet('preview.totalAmount', null)->assertSet('preview.selectionHash', null)
            ->assertSee('PAYER_POLICY_UNCONFIGURED')->assertSee('Total belum tersedia')->assertDontSee('IDR 777');
    }

    #[DataProvider('names')]
    public function test_nullable_or_blank_profile_only_changes_display(?string $name, string $expected): void
    {
        DB::table('participants')->where('id', $this->fixtures[0]['participant'])->update(['full_name' => $name]);
        $this->harness()->set('selection', [Fixture::selection($this->fixtures[0])])->call('review')
            ->assertSet('preview.items.0.participantName', $expected)->assertSee($expected)->assertSee('ATTEMPT-0');
        $this->assertSame($name, DB::table('participants')->where('id', $this->fixtures[0]['participant'])->value('full_name'));
    }

    public static function names(): iterable
    {
        yield [null, 'Nama belum dilengkapi'];
        yield ['', 'Nama belum dilengkapi'];
        yield [" \t ", 'Nama belum dilengkapi'];
        yield ['Nama lengkap sintetis', 'Nama lengkap sintetis'];
    }

    public function test_all_free_then_consultation_uses_server_counts_and_amounts(): void
    {
        $this->harness()->set('selection', [Fixture::selection($this->fixtures[4])])->call('review')
            ->assertSet('preview.totalAmount', 0)->assertSet('preview.freeCount', 1)->assertSee('IDR 0')
            ->set('selection.0.consultationRequested', true)->assertSet('preview', null)->call('review')
            ->assertSet('preview.totalAmount', 30)->assertSet('preview.paidCount', 1)->assertSet('preview.freeCount', 0);
    }

    #[DataProvider('invalidSelections')]
    public function test_malformed_selection_is_rejected_without_old_preview(string $case): void
    {
        $valid = Fixture::selection($this->fixtures[0]);
        $invalid = match ($case) {
            'empty' => [],
            'duplicate' => [$valid, $valid],
            'limit' => array_map(fn (int $id): array => ['assessmentParticipantId' => $id, 'consultationRequested' => false], range(1, config('assessment_billing.max_items') + 1)),
            'scope' => [['organizationId' => $this->foreign['organization'], ...$valid]],
            'price' => [['totalAmount' => 1, ...$valid]],
            'bool' => [['assessmentParticipantId' => $valid['assessmentParticipantId'], 'consultationRequested' => 'false']],
            'id-type' => [['assessmentParticipantId' => (string) $valid['assessmentParticipantId'], 'consultationRequested' => false]],
        };
        $this->harness()->set('selection', [$valid])->call('review')->assertSet('preview.totalAmount', 100)
            ->set('selection', $invalid)->call('review')->assertHasErrors('selection')->assertSet('preview', null)
            ->assertDontSee('Hasil tinjauan sementara');
    }

    public static function invalidSelections(): iterable
    {
        foreach (['empty', 'duplicate', 'limit', 'scope', 'price', 'bool', 'id-type'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_direct_action_foreign_and_nonexistent_ids_do_not_leak_labels(): void
    {
        $component = $this->harness()->call('toggleAttempt', $this->foreign['attempt'])
            ->call('toggleAttempt', PHP_INT_MAX)->call('review')
            ->assertSet('preview.totalAmount', null)->assertSet('preview.selectionHash', null)
            ->assertSee('ASSESSMENT_NOT_AVAILABLE')->assertDontSee('FOREIGN-PRIVATE-SENTINEL');
        foreach ($component->get('preview')['items'] as $item) {
            $this->assertSame(['assessmentParticipantId', 'status', 'reason'], array_keys($item));
        }
    }

    public function test_membership_change_removes_old_labels_on_hydration_and_scopes_direct_review(): void
    {
        $component = $this->harness()->set('selection', [Fixture::selection($this->fixtures[0])])->call('review');
        DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $this->foreign['organization']]);
        $component->call('$refresh')->assertSet('preview', null)->assertDontSee('Peserta sintetis')
            ->assertDontSee('ATTEMPT-0')->call('review')->assertSet('preview.totalAmount', null)
            ->assertSee('ASSESSMENT_NOT_AVAILABLE')->assertDontSee('Peserta sintetis');
    }

    #[DataProvider('denials')]
    public function test_role_guest_deleted_and_public_environment_deny_direct_review(string $case): void
    {
        $component = $this->harness()->set('selection', [Fixture::selection($this->fixtures[0])])->call('review');
        match ($case) {
            'guest' => auth('admin')->logout(),
            'deleted' => DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]),
            'production' => app()->instance('env', 'production'),
            default => DB::table('admins')->where('id', $this->admin->id)->update(['role' => $case]),
        };
        $component->call('review')->assertForbidden();
        Livewire::test(Component::class, ['attemptIds' => array_column($this->fixtures, 'attempt')])->assertForbidden();
    }

    public static function denials(): iterable
    {
        foreach (['guest', 'deleted', 'staff', 'psychologist', 'super_admin', 'production'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('lockedProperties')]
    public function test_browser_cannot_replace_server_choices_or_preview(string $property, array $value): void
    {
        $component = $this->harness();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set($property, $value);
    }

    public static function lockedProperties(): iterable
    {
        yield ['attemptIds', [999999]];
        yield ['preview', ['totalAmount' => 1, 'selectionHash' => 'forged']];
    }

    public function test_component_actions_issue_no_billing_or_audit_writes(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->harness()->call('toggleAttempt', $this->fixtures[0]['attempt'])->call('review')
                ->set('selection.0.consultationRequested', true)->call('review')->call('$refresh');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query['query']);
        }
    }

    public function test_refresh_reloads_nullable_profile_labels_without_preserving_old_result(): void
    {
        $component = $this->harness()->set('selection', [Fixture::selection($this->fixtures[0])])->call('review')
            ->assertSee('Peserta sintetis 0');
        DB::table('participants')->where('id', $this->fixtures[0]['participant'])->update(['full_name' => null]);
        $component->call('$refresh')->assertSet('preview', null)->assertDontSee('Peserta sintetis 0')
            ->assertDontSee('Hasil tinjauan sementara')->assertSee('Nama belum dilengkapi')->assertSee('ATTEMPT-0');
    }

    public function test_toggling_does_not_sanitize_malformed_hydrated_rows_into_valid_selection(): void
    {
        $this->harness()->set('selection', ['malformed-row'])->call('toggleAttempt', $this->fixtures[0]['attempt'])
            ->assertSet('selection.0', 'malformed-row')->call('review')->assertHasErrors('selection')->assertSet('preview', null);
    }

    private function harness(): Testable
    {
        return Livewire::test(Component::class, ['attemptIds' => array_column($this->fixtures, 'attempt')])->assertSuccessful();
    }

    private function mixedSelection(): array
    {
        return array_map(fn (array $fixture, int $index): array => Fixture::selection($fixture, in_array($index, [1, 3, 5, 9], true)), $this->fixtures, array_keys($this->fixtures));
    }

    private function effectRows(): array
    {
        $rows = [];
        foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'audit_logs', 'outbox_messages', 'consent_records', 'identity_verifications'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
