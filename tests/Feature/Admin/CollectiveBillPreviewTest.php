<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Actions\PreviewCollectiveBillSelection;
use App\Models\Admin;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class CollectiveBillPreviewTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->fixture = $this->fixture();
        $this->admin = Admin::create(['branch_id' => $this->fixture['organization'], 'name' => 'Synthetic admin',
            'email' => 'collective-preview@example.test', 'password' => 'synthetic-password',
            'role' => AdminRole::BranchAdmin, 'can_verify_payments' => false]);
        $this->actingAs($this->admin, 'admin');
    }

    public function test_ten_mixed_package_attempts_project_server_prices_and_only_safe_fields(): void
    {
        $groups = ['A', 'A', 'B', 'B', 'C', 'C', 'A', 'B', 'C', 'A'];
        $consultation = [false, true, false, true, false, true, false, false, false, true];
        $packageCodes = $selection = $fixtures = [];
        foreach ($groups as $index => $group) {
            $fixture = $index === 0 ? $this->fixture : $this->fixture($this->fixture['organization']);
            $code = 'SYN-'.$group.'-'.$index;
            DB::table('packages')->where('id', $fixture['package'])->update([
                'code' => $code, 'name' => 'Synthetic '.$group,
                'amount' => ['A' => 100, 'B' => 200, 'C' => 0][$group],
                'consultation_amount' => $group === 'B' ? 50 : 30]);
            DB::table('integration_sources')->where('id', $fixture['source'])
                ->update(['allowed_assessment_packages' => json_encode([$code], JSON_THROW_ON_ERROR)]);
            $selection[] = Fixture::selection($fixture, $consultation[$index]);
            $fixtures[] = $fixture;
            $packageCodes[] = $code;
        }

        $result = $this->preview($selection);
        $this->assertSame(['items', 'currency', 'totalAmount', 'paidCount', 'freeCount', 'canReserve', 'selectionHash'], array_keys($result));
        $this->assertSame([100, 130, 200, 250, 0, 30, 100, 200, 0, 130], array_column($result['items'], 'amount'));
        $this->assertSame(1140, $result['totalAmount']);
        $this->assertSame(8, $result['paidCount']);
        $this->assertSame(2, $result['freeCount']);
        $this->assertTrue($result['canReserve']);
        $this->assertSame('IDR', $result['currency']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['selectionHash']);
        foreach ($result['items'] as $index => $item) {
            $this->assertSame(['assessmentParticipantId', 'status', 'reason', 'participantName', 'externalCandidateId',
                'assessmentAttemptId', 'period', 'packageCode', 'packageName', 'baseAmount',
                'consultationRequested', 'consultationAmount', 'amount', 'currency'], array_keys($item));
            $this->assertSame($fixtures[$index]['attempt'], $item['assessmentParticipantId']);
            $this->assertSame('CANDIDATE-'.$fixtures[$index]['attempt'], $item['externalCandidateId']);
            $this->assertSame('Synthetic participant', $item['participantName']);
            $this->assertSame('Synthetic period', $item['period']);
            $this->assertSame($packageCodes[$index], $item['packageCode']);
            $this->assertSame($consultation[$index], $item['consultationRequested']);
            $this->assertSame(in_array($index, [4, 8], true) ? 'free' : 'payable', $item['status']);
        }
        $this->assertStringNotContainsString('PRIVATE-SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($result, $this->preview(array_reverse($selection)));
        $selection[4]['consultationRequested'] = $selection[8]['consultationRequested'] = true;
        $allPayable = $this->preview($selection);
        $this->assertSame(1200, $allPayable['totalAmount']);
        $this->assertSame(10, $allPayable['paidCount']);
        $this->assertSame(0, $allPayable['freeCount']);
    }

    #[DataProvider('names')]
    public function test_name_fallback_changes_only_projection(?string $name, string $expected): void
    {
        DB::table('participants')->where('id', $this->fixture['participant'])->update(['full_name' => $name]);
        $this->assertSame($expected, $this->preview()['items'][0]['participantName']);
        $this->assertSame($name, DB::table('participants')->where('id', $this->fixture['participant'])->value('full_name'));
    }

    public static function names(): iterable
    {
        yield [null, 'Nama belum dilengkapi'];
        yield ['', 'Nama belum dilengkapi'];
        yield [" \t\r\n ", 'Nama belum dilengkapi'];
        yield ['  Ayu Sintetis  ', '  Ayu Sintetis  '];
    }

    public function test_all_free_has_zero_total_and_cannot_reserve(): void
    {
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 0]);
        $result = $this->preview();
        $this->assertSame('free', $result['items'][0]['status']);
        $this->assertSame(0, $result['totalAmount']);
        $this->assertSame(1, $result['freeCount']);
        $this->assertFalse($result['canReserve']);
    }

    public function test_foreign_and_nonexistent_ids_never_project_labels_or_partial_total(): void
    {
        $foreign = $this->fixture(name: 'FOREIGN-PRIVATE-SENTINEL');
        $result = $this->preview([Fixture::selection($this->fixture), Fixture::selection($foreign),
            ['assessmentParticipantId' => 999999, 'consultationRequested' => false]]);
        foreach (array_slice($result['items'], 1) as $item) {
            $this->assertSame(['assessmentParticipantId', 'status', 'reason'], array_keys($item));
            $this->assertSame('unavailable', $item['status']);
            $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $item['reason']);
        }
        $this->assertNull($result['totalAmount']);
        $this->assertNull($result['selectionHash']);
        $this->assertFalse($result['canReserve']);
        $this->assertStringNotContainsString('FOREIGN', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_policy_is_reloaded_and_invalid_item_invalidates_the_whole_total(): void
    {
        $other = $this->fixture($this->fixture['organization']);
        $selection = [Fixture::selection($this->fixture), Fixture::selection($other)];
        $this->assertSame(200, $this->preview($selection)['totalAmount']);
        DB::table('integration_sources')->where('id', $other['source'])->update(['allowed_payer_types' => '["self"]']);
        $result = $this->preview($selection);
        $this->assertSame('PAYER_NOT_ALLOWED', $result['items'][1]['reason']);
        $this->assertNull($result['totalAmount']);
        $this->assertFalse($result['canReserve']);
    }

    public function test_existing_snapshot_is_used_without_repricing_or_mutation(): void
    {
        $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->fixture['package']), false);
        $charge = AssessmentCharge::create(['assessment_participant_id' => $this->fixture['attempt'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'package_id' => $this->fixture['package'], 'payer_type' => 'organization', 'base_amount' => 100,
            'consultation_amount' => 0, 'consultation_requested' => false, 'amount' => 100, 'currency' => 'IDR',
            'price_snapshot' => $snapshot, 'policy_snapshot' => ['private' => 'PRIVATE-SENTINEL']]);
        $before = $charge->refresh()->getAttributes();
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 999]);
        $this->assertSame(100, $this->preview()['totalAmount']);
        $this->assertSame($before, $charge->refresh()->getAttributes());
    }

    #[DataProvider('deniedRoles')]
    public function test_persisted_role_rejects_stale_session_and_legacy_verifier_flag(AdminRole $role): void
    {
        $this->preview();
        DB::table('admins')->where('id', $this->admin->id)->update(['role' => $role->value, 'can_verify_payments' => true]);
        $this->expectException(AuthorizationException::class);
        $this->preview();
    }

    public static function deniedRoles(): iterable
    {
        yield [AdminRole::Staff];
        yield [AdminRole::Psychologist];
        yield [AdminRole::SuperAdmin];
    }

    public function test_membership_and_memory_scope_changes_use_only_current_persisted_scope(): void
    {
        $foreign = $this->fixture(name: 'New branch participant');
        $this->admin->branch_id = $foreign['organization'];
        $this->assertSame('Synthetic participant', $this->preview()['items'][0]['participantName']);
        $this->assertNull($this->preview([Fixture::selection($foreign)])['totalAmount']);
        DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $foreign['organization']]);
        $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $this->preview()['items'][0]['reason']);
        $this->assertSame('New branch participant', $this->preview([Fixture::selection($foreign)])['items'][0]['participantName']);
    }

    #[DataProvider('invalidPrincipals')]
    public function test_missing_or_unauthorized_principal_is_denied(string $case): void
    {
        match ($case) {
            'guest' => auth('admin')->logout(),
            'non-admin' => Filament::auth()->setUser(new GenericUser(['id' => $this->fixture['participant']])),
            'deleted' => DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]),
            'branchless' => DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => null]),
        };
        $this->expectException(AuthorizationException::class);
        $this->preview();
    }

    public static function invalidPrincipals(): iterable
    {
        foreach (['guest', 'non-admin', 'deleted', 'branchless'] as $case) {
            yield [$case];
        }
    }

    #[DataProvider('nonTestingEnvironments')]
    public function test_gate_stays_closed_outside_testing(string $environment): void
    {
        app()->instance('env', $environment);
        try {
            $this->expectException(AuthorizationException::class);
            $this->preview();
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public static function nonTestingEnvironments(): iterable
    {
        yield ['local'];
        yield ['production'];
    }

    #[DataProvider('invalidSelections')]
    public function test_scope_payer_totals_and_malformed_selection_cannot_be_injected(array $selection): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preview($selection);
    }

    public static function invalidSelections(): iterable
    {
        yield [[]];
        yield [['organizationId' => 1, 'payer' => 'self', 'totalAmount' => 1, 'selection' => []]];
        yield [[['assessmentParticipantId' => 1, 'consultationRequested' => false, 'organizationId' => 1]]];
        yield [[['assessmentParticipantId' => '1', 'consultationRequested' => false]]];
        yield [[['assessmentParticipantId' => 1, 'consultationRequested' => false], ['assessmentParticipantId' => 1, 'consultationRequested' => false]]];
    }

    public function test_admin_rls_context_is_restored_after_preview_and_validation_failure(): void
    {
        $runner = app(RlsContextRunner::class);
        $context = new RlsContext('branch_admin', $this->fixture['organization']);
        $runner->run($context, function () use ($runner, $context): void {
            $this->preview();
            $this->assertSame($context, $runner->current());
            try {
                $this->preview([]);
                $this->fail('Invalid selection accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame($context, $runner->current());
            }
        });
        $this->assertNull($runner->current());
    }

    private function fixture(?int $organization = null, int $amount = 100, ?string $name = 'Synthetic participant'): array
    {
        $fixture = Fixture::create($organization === null ? null : ['organization' => $organization], $amount);
        DB::table('package_items')->insert([
            'package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => $name]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'external_candidate_id' => 'CANDIDATE-'.$fixture['attempt'], 'assessment_round_id' => 'Synthetic period',
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2', 'private' => 'PRIVATE-SENTINEL'], JSON_THROW_ON_ERROR)]);

        return $fixture;
    }

    private function preview(?array $selection = null): array
    {
        $tables = ['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'audit_logs', 'outbox_messages', 'consent_records', 'identity_verifications'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            return app(PreviewCollectiveBillSelection::class)->execute($selection ?? [Fixture::selection($this->fixture)]);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query['query']);
            }
        }
    }
}
