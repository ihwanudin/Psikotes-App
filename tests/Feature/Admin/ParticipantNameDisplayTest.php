<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\AssessmentParticipants\AssessmentParticipantResource;
use App\Filament\Resources\AssessmentParticipants\Pages\ListAssessmentParticipants;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Admin;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

final class ParticipantNameDisplayTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->branch = Branch::create(['code' => 'DISPLAY-A', 'name' => 'Synthetic A', 'ref_code' => 'DISPLAY-A']);
        $this->admin = Admin::create(['name' => 'Synthetic admin', 'email' => 'display@example.test',
            'password' => 'synthetic-password', 'role' => AdminRole::BranchAdmin,
            'branch_id' => $this->branch->id, 'can_verify_payments' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    #[DataProvider('names')]
    public function test_missing_name_is_display_only_and_existing_identifiers_distinguish_rows(?string $name): void
    {
        [$participant, $attempt, $order] = $this->records('A', $this->branch, $name);
        [, $otherAttempt, $otherOrder] = $this->records('B', $this->branch, null);
        $before = (array) DB::table('participants')->where('id', $participant->id)->first();
        foreach ([[ListAssessmentParticipants::class, $attempt, $otherAttempt, 'external_candidate_id'],
            [ListOrders::class, $order, $otherOrder, 'public_id']] as [$page, $record, $other, $identifier]) {
            $list = Livewire::test($page)->assertSuccessful()
                ->assertCanSeeTableRecords([$record, $other])
                ->assertTableColumnStateSet($identifier, $record->$identifier, $record)
                ->assertTableColumnStateSet($identifier, $other->$identifier, $other);
            $this->assertNotSame($record->$identifier, $other->$identifier);
            $column = $list->instance()->getTable()->getColumn('participant.full_name')->record($record);
            $this->assertTrue($column->isSearchable());
            $this->assertTrue($column->isSortable());
            $this->assertStringContainsString(blank($name) ? 'Nama belum dilengkapi' : $name, $column->toHtml());
            $this->assertStringContainsString('Nama belum dilengkapi', $column->record($other)->toHtml());
            if (filled($name)) {
                $list->assertTableColumnStateSet('participant.full_name', $name, $record);
            }
            // Both identifiers remain visible; Order keeps its existing truncation/tooltip.
            $list->assertSee(substr($record->$identifier, 0, 14))->assertSee(substr($other->$identifier, 0, 14));
        }
        $this->assertSame($before, (array) DB::table('participants')->where('id', $participant->id)->first());
        $this->assertSame($name, DB::table('participants')->where('id', $participant->id)->value('full_name'));
    }

    public static function names(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\r\n "];
        yield 'complete' => ['Ayu Sintetis'];
        yield 'nonblank preserved' => ['  Ayu Sintetis  '];
    }

    public function test_search_sort_and_cross_tenant_scope_still_use_stored_values(): void
    {
        [, $ayuAttempt, $ayuOrder] = $this->records('A', $this->branch, 'Ayu Sintetis');
        [, $zahraAttempt, $zahraOrder] = $this->records('B', $this->branch, 'Zahra Sintetis');
        $foreign = Branch::create(['code' => 'DISPLAY-X', 'name' => 'Synthetic X', 'ref_code' => 'DISPLAY-X']);
        [, $foreignAttempt, $foreignOrder] = $this->records('X', $foreign, null);
        foreach ([[ListAssessmentParticipants::class, $ayuAttempt, $zahraAttempt, $foreignAttempt],
            [ListOrders::class, $ayuOrder, $zahraOrder, $foreignOrder]] as [$page, $ayu, $zahra, $hidden]) {
            $list = Livewire::test($page)->assertCanNotSeeTableRecords([$hidden])
                ->sortTable('participant.full_name')->assertCanSeeTableRecords([$ayu, $zahra], inOrder: true);
            $list->searchTable('Zahra')->assertCanSeeTableRecords([$zahra])->assertCanNotSeeTableRecords([$ayu, $hidden]);
            $list->searchTable('Nama belum dilengkapi')->assertCountTableRecords(0);
        }
        $this->assertNull(AssessmentParticipantResource::resolveRecordRouteBinding($foreignAttempt->id));
        $this->assertNull(OrderResource::resolveRecordRouteBinding($foreignOrder->id));
    }

    public function test_existing_guest_and_payment_role_denials_are_unchanged(): void
    {
        $this->records('A', $this->branch, null);
        foreach ([AdminRole::BranchAdmin, AdminRole::Staff, AdminRole::Psychologist] as $role) {
            $this->admin->update(['role' => $role, 'can_verify_payments' => false]);
            $this->assertSame(0, OrderResource::getEloquentQuery()->count());
            Livewire::test(ListOrders::class)->assertForbidden();
        }
        // Assessment participants intentionally permits these staff roles already.
        // Do not invent a role denial or change that matrix for a display fallback.
        auth('admin')->logout();
        $this->assertFalse(AssessmentParticipantResource::canViewAny());
        $this->assertSame(0, AssessmentParticipantResource::getEloquentQuery()->count());
        $this->assertSame(0, OrderResource::getEloquentQuery()->count());
        $this->get('/admin/assessment-participants')->assertRedirect('/admin/login');
        $this->get('/admin/orders')->assertRedirect('/admin/login');
    }

    /** @return array{Participant, AssessmentParticipant, Order} */
    private function records(string $suffix, Branch $branch, ?string $name): array
    {
        $participant = Participant::create(['branch_id' => $branch->id, 'referral_branch_id' => $branch->id,
            'referral_source' => 'default', 'full_name' => $name, 'gender' => 'female', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM', 'phone' => '620000000000']);
        $package = TestPackage::create(['code' => 'DISPLAY-'.$suffix, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR']);
        $client = IntegrationClient::create(['organization_id' => $branch->id, 'client_id' => 'display-'.$suffix,
            'credential_reference' => 'synthetic-only']);
        $attempt = AssessmentParticipant::create(['organization_id' => $branch->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id, 'assessment_attempt_id' => (string) Str::ulid(),
            'source_system' => 'DISPLAY', 'external_candidate_id' => 'CANDIDATE-'.$suffix, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => 'display-'.$suffix,
            'request_hash' => hash('sha256', $suffix), 'logical_assessment_key' => hash('sha256', 'display-'.$suffix)]);
        $method = PaymentMethod::query()->where('code', 'manual_transfer')->first();
        if ($method === null) {
            $method = new PaymentMethod;
            $method->forceFill(['code' => 'manual_transfer', 'display_name' => 'Synthetic manual', 'is_active' => false])->save();
        }
        $order = Order::create(['public_id' => str_pad('01'.$suffix, 26, '0'), 'participant_id' => $participant->id,
            'payment_method_id' => $method->id, 'amount' => 100, 'currency' => 'IDR', 'status' => 'pending']);

        return [$participant, $attempt, $order];
    }
}
