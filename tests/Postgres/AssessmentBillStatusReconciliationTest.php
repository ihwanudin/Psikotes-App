<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Contracts\PaymentProvider;
use App\Security\RlsContextRunner;
use App\Services\Payments\ReconcilePendingAssessmentBills;
use App\Services\Payments\XenditProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillStatusReconciliationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    private int $method;

    private bool $ownsMethod = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        Date::setTestNow(now()->startOfSecond());
        config()->set('services.xendit.secret_key', 'xnd_test_reconciliation');
        config()->set('services.xendit.base_url', 'https://api.xendit.co');
        app()->instance(PaymentProvider::class, new XenditProvider);
        Http::preventStrayRequests();

        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $method = DB::table('payment_methods')->where('code', 'xendit')->value('id');
            if (! is_int($method)) {
                $method = DB::table('payment_methods')->insertGetId([
                    'code' => 'xendit', 'display_name' => 'Xendit', 'is_active' => true,
                ]);
                $this->ownsMethod = true;
            }
            $this->method = $method;
            $fixture = AssessmentAccessFixture::create();
            DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'PROVISIONED',
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => json_encode([
                    'checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
                ], JSON_THROW_ON_ERROR),
            ]);
            DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
                ->update(['status' => 'locked', 'ready_at' => null]);
            DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'status' => 'pending',
                'paid_at' => null,
                'gateway_ref' => 'xendit-status-'.$fixture['bill'],
                'payment_method_id' => $method,
            ]);

            return [...$fixture,
                'providerReference' => 'xendit-status-'.$fixture['bill'],
                'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference'),
            ];
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('payment_webhook_events')->where('merchant_reference', $this->fixture['reference'])->delete();
            DB::table('outbox_messages')->where('aggregate_id', (string) $this->fixture['attempt'])->delete();
            DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])->delete();
            foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges'] as $table) {
                DB::table($table)->where('organization_id', $this->fixture['organization'])->delete();
            }
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $this->fixture['participant'])->delete();
            }
            if ($this->ownsMethod) {
                DB::table('payment_methods')->where('id', $this->method)->delete();
            }
        });
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_runtime_rls_selection_commits_before_get_and_reenters_processor(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());

            return Http::response([
                'id' => $this->fixture['providerReference'],
                'external_id' => $this->fixture['reference'],
                'status' => 'EXPIRED',
                'amount' => 100,
                'currency' => 'IDR',
                'created' => now()->subHour()->toIso8601String(),
                'updated' => now()->toIso8601String(),
            ]);
        });

        $result = app(ReconcilePendingAssessmentBills::class)->handle(1, 1);

        $this->assertSame([1, 1, 1, 0, 0], [
            $result->scanned, $result->checked, $result->applied, $result->ignored, $result->failed,
        ]);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('expired', DB::table('assessment_bills')
                ->where('id', $this->fixture['bill'])->value('status'));
            $this->assertSame(1, DB::table('payment_webhook_events')
                ->where('merchant_reference', $this->fixture['reference'])->count());
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment_bill.expired')->count());
        });
        Http::assertSentCount(1);
    }
}
