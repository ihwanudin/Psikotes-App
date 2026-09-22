<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\ProvisionAssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPEC.md:259: entitlements may become `ready` only via a verified Xendit
 * webhook, an admin-verified manual transfer, or audited super_admin
 * activation -- never unconditionally at provisioning time. This is
 * Direction A of tasks/handoffs/f2/legacy-entitlement-provisioning-closure-plan.md.
 */
final class ProvisionAssessmentParticipantFundingModeTest extends TestCase
{
    use RefreshDatabase;

    private string $code;

    private IntegrationClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->code = 'FM_'.Str::random(10);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $organization = Branch::query()->create([
                'code' => $this->code,
                'ref_code' => $this->code,
                'name' => 'Funding Mode Test',
                'organization_code' => $this->code,
                'display_name' => 'Funding Mode Test',
                'status' => 'ACTIVE',
                'allowed_funding_modes' => ['COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION', 'SPONSORED', 'INTERNAL', 'WAIVED'],
                'is_active' => true,
            ]);
            $package = TestPackage::query()->create([
                'code' => $this->code,
                'name' => 'Funding Mode Test',
                'amount' => 1000,
                'currency' => 'IDR',
                'is_active' => true,
            ]);
            $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
            $package->items()->create(['test_type' => 'dass21', 'sort_order' => 2]);
            $this->client = IntegrationClient::query()->create([
                'organization_id' => $organization->id,
                'client_id' => $this->code,
                'credential_reference' => 'synthetic',
                'enabled' => true,
            ])->refresh();
            IntegrationSource::query()->create([
                'integration_client_id' => $this->client->id,
                'source_system' => $this->code,
                'contract_version' => 'v1',
                'authentication_mode' => 'HMAC_SHA256',
                'allowed_assessment_packages' => [$this->code],
                'participant_provisioning_mode' => 'API',
                'commercial_mode' => 'CONTRACT',
                'allowed_funding_modes' => ['COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION', 'SPONSORED', 'INTERNAL', 'WAIVED'],
                'status' => 'ACTIVE',
            ]);
        });
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function fundingModes(): iterable
    {
        yield 'COMMERCIAL_SELF_PAY is locked, real money not yet verified' => ['COMMERCIAL_SELF_PAY', 'locked', null];
        yield 'INVOICED_TO_ORGANIZATION is locked, real money not yet verified' => ['INVOICED_TO_ORGANIZATION', 'locked', null];
        yield 'SPONSORED stays ready, Direction B not decided yet' => ['SPONSORED', 'ready', 'set'];
        yield 'INTERNAL stays ready, Direction B not decided yet' => ['INTERNAL', 'ready', 'set'];
        yield 'WAIVED stays ready, Direction B not decided yet' => ['WAIVED', 'ready', 'set'];
    }

    #[DataProvider('fundingModes')]
    public function test_entitlement_status_matches_funding_mode(string $fundingMode, string $expectedStatus, ?string $expectedReadyAt): void
    {
        app(ProvisionAssessmentParticipant::class)->handle([
            'sourceSystem' => $this->code,
            'externalCandidateId' => 'CANDIDATE-'.$fundingMode,
            'externalProcessId' => 'PROCESS-1',
            'externalRegistrationId' => 'REGISTRATION-1',
            'assessmentRoundId' => 'ROUND-1',
            'organizationCode' => $this->code,
            'assessmentPackageCode' => $this->code,
            'fundingMode' => $fundingMode,
            'profile' => [
                'fullName' => 'Funding Mode Participant',
                'birthDate' => '2000-01-01',
                'gender' => 'MALE',
                'educationLevel' => 'SMA',
                'email' => strtolower($fundingMode).'@example.test',
                'phone' => '628123456789',
            ],
            'metadata' => [],
        ], $this->client, 'key-'.$fundingMode);

        app(RlsContextRunner::class)->runAsService(function () use ($expectedStatus, $expectedReadyAt): void {
            $entitlements = DB::table('entitlements')
                ->join('participants', 'participants.id', '=', 'entitlements.participant_id')
                ->where('participants.branch_id', function ($query): void {
                    $query->select('id')->from('branches')->where('code', $this->code);
                })
                ->get(['entitlements.status', 'entitlements.ready_at', 'entitlements.order_id']);

            $this->assertCount(2, $entitlements);
            foreach ($entitlements as $entitlement) {
                $this->assertSame($expectedStatus, $entitlement->status);
                $this->assertNull($entitlement->order_id, 'Integration-provisioned entitlements never carry an order_id, money-verified or not.');
                if ($expectedReadyAt === null) {
                    $this->assertNull($entitlement->ready_at);
                } else {
                    $this->assertNotNull($entitlement->ready_at);
                }
            }
        });
    }
}
