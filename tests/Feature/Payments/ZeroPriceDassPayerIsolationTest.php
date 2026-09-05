<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class ZeroPriceDassPayerIsolationTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private int $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentMethod = DB::table('payment_methods')->insertGetId([
            'code' => 'manual_transfer', 'display_name' => 'Synthetic transfer', 'is_active' => true,
        ]);
    }

    public function test_free_package_without_consultation_creates_no_bill_while_consultation_uses_server_snapshot(): void
    {
        $fixture = $this->freeDassFixture();
        $selection = [['assessmentParticipantId' => $fixture['attempt'], 'consultationRequested' => false]];
        $preview = $this->preview($fixture['organization'], $selection, PayerType::Organization);

        $this->assertSame(['dass21', 'ist'], $preview['items'][0]['snapshot']['testTypes']);
        $this->assertSame(0, $preview['items'][0]['snapshot']['baseAmount']);
        $this->assertSame(0, $preview['items'][0]['snapshot']['consultationAmount']);
        $this->assertSame(0, $preview['totalAmount']);
        $this->assertSame(1, $preview['freeCount']);
        $this->assertSame(0, $preview['paidCount']);
        $this->assertFalse($preview['canReserve']);

        try {
            $this->reserve($this->admin($fixture['organization'], 'free'), $selection, $preview['selectionHash'], 'free-no-consultation');
            $this->fail('A free selection created a bill.');
        } catch (DomainException $exception) {
            $this->assertSame('FREE_CHECKOUT_REQUIRED', $exception->getMessage());
        }
        $this->assertNoBillingRows();

        $consultationSelection = [['assessmentParticipantId' => $fixture['attempt'], 'consultationRequested' => true]];
        $consultation = $this->preview($fixture['organization'], $consultationSelection, PayerType::Organization);
        $snapshot = $consultation['items'][0]['snapshot'];
        $this->assertSame(50_000, $snapshot['consultationAmount']);
        $this->assertSame($snapshot['amount'], $consultation['totalAmount']);
        $this->assertSame(50_000, $consultation['totalAmount']);
        $this->assertSame('organization', $consultation['items'][0]['policySnapshot']['payerType']);

        $bill = $this->reserve(
            $this->admin($fixture['organization'], 'consultation'),
            $consultationSelection,
            $consultation['selectionHash'],
            'consultation-reservation',
        );
        $this->assertSame($snapshot['amount'], $bill->amount);
        $this->assertSame('organization', $bill->payer_type);
        $this->assertNull($bill->payer_participant_id);
        $this->assertDatabaseHas('assessment_charges', [
            'assessment_participant_id' => $fixture['attempt'], 'base_amount' => $snapshot['baseAmount'],
            'consultation_amount' => $snapshot['consultationAmount'], 'amount' => $snapshot['amount'],
            'payer_type' => 'organization',
        ]);
    }

    public function test_payer_identity_and_tenant_scope_are_derived_without_cross_participant_projection(): void
    {
        $own = $this->freeDassFixture();
        $foreign = $this->freeDassFixture();
        $selection = [['assessmentParticipantId' => $own['attempt'], 'consultationRequested' => true]];

        $organizationPreview = $this->preview($own['organization'], $selection, PayerType::Organization);
        $selfPreview = $this->preview($own['organization'], $selection, PayerType::SelfPay, $own['participant']);
        $this->assertSame(['dass21', 'ist'], $organizationPreview['items'][0]['snapshot']['testTypes']);
        $this->assertSame(['dass21', 'ist'], $selfPreview['items'][0]['snapshot']['testTypes']);
        $this->assertSame('organization', $organizationPreview['items'][0]['policySnapshot']['payerType']);
        $this->assertSame('self', $selfPreview['items'][0]['policySnapshot']['payerType']);
        $this->assertNotSame($organizationPreview['selectionHash'], $selfPreview['selectionHash']);
        $this->assertSame($organizationPreview['totalAmount'], $selfPreview['totalAmount']);

        $wrongParticipant = $this->preview($own['organization'], $selection, PayerType::SelfPay, $foreign['participant']);
        $wrongOrganization = $this->preview($foreign['organization'], $selection, PayerType::Organization);
        foreach ([$wrongParticipant, $wrongOrganization] as $denied) {
            $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $denied['items'][0]['reason']);
            $this->assertNull($denied['items'][0]['snapshot']);
            $this->assertNull($denied['items'][0]['policySnapshot']);
            $this->assertNull($denied['totalAmount']);
            $this->assertNull($denied['selectionHash']);
        }
        foreach ([
            DB::table('participants')->where('id', $own['participant'])->value('full_name'),
            DB::table('packages')->where('id', $own['package'])->value('name'),
            DB::table('packages')->where('id', $own['package'])->value('code'),
        ] as $privateValue) {
            $this->assertStringNotContainsString((string) $privateValue, json_encode([$wrongParticipant, $wrongOrganization], JSON_THROW_ON_ERROR));
        }

        $bill = $this->reserve(
            Participant::query()->whereKey($own['participant'])->sole(),
            $selection,
            $selfPreview['selectionHash'],
            'self-consultation',
        );
        $this->assertSame('self', $bill->payer_type);
        $this->assertSame($own['participant'], $bill->payer_participant_id);
        $this->assertDatabaseHas('assessment_bill_items', [
            'bill_id' => $bill->id, 'organization_id' => $own['organization'],
            'participant_id' => $own['participant'], 'payer_type' => 'self',
            'payer_participant_id' => $own['participant'],
        ]);
        $this->assertDatabaseMissing('assessment_bill_items', ['organization_id' => $foreign['organization']]);
    }

    /**
     * @return array{organization: int, participant: int, package: int, attempt: int}
     */
    private function freeDassFixture(): array
    {
        $raw = Fixture::create(amount: 0);
        $organization = $raw['organization'] ?? null;
        $participant = $raw['participant'] ?? null;
        $package = $raw['package'] ?? null;
        $attempt = $raw['attempt'] ?? null;
        if (! is_int($organization) || ! is_int($participant) || ! is_int($package) || ! is_int($attempt)) {
            throw new \LogicException('Synthetic billing fixture returned invalid identifiers.');
        }
        DB::table('participants')->where('id', $participant)->update([
            'full_name' => 'PRIVATE PARTICIPANT '.$participant,
        ]);
        DB::table('packages')->where('id', $package)->update([
            'name' => 'PRIVATE PACKAGE '.$package, 'consultation_amount' => 50_000,
        ]);
        DB::table('package_items')->insert([
            'package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2,
        ]);

        return compact('organization', 'participant', 'package', 'attempt');
    }

    private function admin(int $organization, string $suffix): Admin
    {
        return Admin::create([
            'branch_id' => $organization, 'name' => 'Synthetic branch admin',
            'email' => $suffix.'-zero-price@example.test', 'password' => 'synthetic-password',
            'role' => AdminRole::BranchAdmin,
        ]);
    }

    /**
     * @param  list<array{assessmentParticipantId: int, consultationRequested: bool}>  $selection
     * @return array<string, mixed>
     */
    private function preview(int $organization, array $selection, PayerType $payer, ?int $participant = null): array
    {
        return app(RlsContextRunner::class)->runAsService(
            fn (): array => app(PreviewAssessmentBill::class)->execute($organization, $selection, $payer, $participant),
        );
    }

    /** @param list<array{assessmentParticipantId: int, consultationRequested: bool}> $selection */
    private function reserve(Admin|Participant $principal, array $selection, string $hash, string $key): AssessmentBill
    {
        return app(RlsContextRunner::class)->runAsService(
            fn () => app(ReserveAssessmentBill::class)->execute($principal, $selection, $this->paymentMethod, $hash, $key),
        );
    }

    private function assertNoBillingRows(): void
    {
        foreach (['assessment_bills', 'assessment_charges', 'assessment_bill_items', 'audit_logs', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
