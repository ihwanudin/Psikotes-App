<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Enums\PayerType;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentBillPreviewTest extends TestCase
{
    private array $own;

    private array $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->own = Fixture::create();
            $this->foreign = Fixture::create();
        });
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_runtime_preview_prices_ten_attempts_without_billing_writes(): void
    {
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $selection = [Fixture::selection($this->own, true)];
            for ($index = 1; $index < 10; $index++) {
                $fixture = Fixture::create(['organization' => $this->own['organization']], $index === 9 ? 0 : 100);
                $selection[] = Fixture::selection($fixture);
            }
            $tables = ['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
                'orders', 'entitlements', 'outbox_messages'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->count();
            }
            $result = app(PreviewAssessmentBill::class)->execute($this->own['organization'], $selection, PayerType::Organization);
            $this->assertSame(930, $result['totalAmount']);
            $this->assertSame(9, $result['paidCount']);
            $this->assertSame(1, $result['freeCount']);
            $this->assertTrue($result['canReserve']);
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->count(), $table);
            }
        });
    }

    public function test_cross_branch_attempt_is_redacted_even_in_service_context(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $result = app(PreviewAssessmentBill::class)->execute($this->own['organization'],
                [Fixture::selection($this->own), Fixture::selection($this->foreign)], PayerType::Organization);
            $this->assertNull($result['totalAmount']);
            $this->assertFalse($result['canReserve']);
            $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $result['items'][1]['reason']);
            $this->assertNull($result['items'][1]['snapshot']);
            $this->assertNull($result['items'][1]['policySnapshot']);
        });
    }

    #[DataProvider('roles')]
    public function test_non_service_context_cannot_call_internal_preview(string $role): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Preview requires service RLS context.');
        app(RlsContextRunner::class)->run(new RlsContext($role, $this->own['organization'], $this->own['participant']),
            fn () => app(PreviewAssessmentBill::class)->execute($this->own['organization'], [Fixture::selection($this->own)], PayerType::Organization));
    }

    public static function roles(): iterable
    {
        yield ['participant'];
        yield ['branch_admin'];
        yield ['super_admin'];
    }

    public function test_stored_snapshot_is_preserved_and_policy_is_reloaded(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->own['package']), false);
            $charge = AssessmentCharge::create(['assessment_participant_id' => $this->own['attempt'], 'organization_id' => $this->own['organization'],
                'participant_id' => $this->own['participant'], 'package_id' => $this->own['package'], 'payer_type' => 'organization',
                'base_amount' => 100, 'consultation_amount' => 0, 'amount' => 100, 'currency' => 'IDR',
                'price_snapshot' => $snapshot, 'policy_snapshot' => ['payerType' => 'organization']]);
            DB::table('packages')->where('id', $this->own['package'])->update(['amount' => 999]);
            $preview = app(PreviewAssessmentBill::class);
            $this->assertSame(100, $preview->execute($this->own['organization'], [Fixture::selection($this->own)], PayerType::Organization)['totalAmount']);
            DB::table('integration_sources')->where('id', $this->own['source'])->update(['allowed_payer_types' => '["self"]']);
            $result = $preview->execute($this->own['organization'], [Fixture::selection($this->own)], PayerType::Organization);
            $this->assertSame('PAYER_NOT_ALLOWED', $result['items'][0]['reason']);
            $this->assertSame(100, $charge->refresh()->amount);
            $this->assertSame($snapshot, $charge->price_snapshot);
        });
    }

}
