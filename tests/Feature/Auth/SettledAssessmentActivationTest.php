<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Payments\ActivateSettledAssessment;
use App\Models\AssessmentCharge;
use App\Models\AssessmentEntitlement;
use App\Security\RlsContextRunner;
use App\Services\Notifications\DispatchNotificationOutbox;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class SettledAssessmentActivationTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->f = $this->pending();
    }

    private function pending(?array $identity = null): array
    {
        $f = Fixture::create(identity: $identity);
        DB::table('assessment_participants')->where('id', $f['attempt'])->update(['assessment_status' => 'PROVISIONED']);
        DB::table('assessment_entitlements')->where('assessment_participant_id', $f['attempt'])
            ->update(['status' => 'locked', 'ready_at' => null]);

        return $f;
    }

    private function activate(?array $f = null): array
    {
        $f ??= $this->f;

        return app(RlsContextRunner::class)->runAsService(fn () => app(ActivateSettledAssessment::class)->execute(
            new AssessmentPrincipal($f['participant'], $f['organization'], $f['attempt'])));
    }

    public function test_settlement_activates_once_with_atomic_outbox_and_audit(): void
    {
        $this->assertSame(['dass21', 'ist'], $this->activate());
        $readyAt = AssessmentEntitlement::findOrFail($this->f['entitlement'])->ready_at;
        $this->travel(1)->minutes();
        $this->assertSame([], $this->activate());
        $this->assertTrue($readyAt->equalTo(AssessmentEntitlement::findOrFail($this->f['entitlement'])->ready_at));
        $this->assertDatabaseHas('assessment_participants', ['id' => $this->f['attempt'], 'assessment_status' => 'READY']);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', ['topic' => 'assessment.activation', 'aggregate_id' => (string) $this->f['attempt']]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment.activated')->count());
        app(RlsContextRunner::class)->runAsService(fn () => $this->assertSame($this->f['entitlement'],
            app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal(
                $this->f['participant'], $this->f['organization'], $this->f['attempt']), 'ist')->id));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('entitlements', 0);
    }

    #[DataProvider('blockedStates')]
    public function test_ineligible_attempt_does_not_write_access(string $table, array $values): void
    {
        DB::table($table)->update($values);
        $this->assertSame([], $this->activate());
        $this->assertDatabaseHas('assessment_entitlements', ['id' => $this->f['entitlement'], 'status' => 'locked']);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function blockedStates(): iterable
    {
        yield 'pending' => ['assessment_bills', ['status' => 'pending', 'paid_at' => null]];
        yield 'expired' => ['assessment_bills', ['status' => 'expired', 'paid_at' => null]];
        yield 'rejected' => ['assessment_bills', ['status' => 'rejected', 'paid_at' => null]];
        yield 'no allocation' => ['assessment_bill_items', ['settled_at' => null]];
        yield 'wrong total' => ['assessment_bills', ['amount' => 200]];
        yield 'wrong count' => ['assessment_bills', ['item_count' => 2]];
        yield 'future paid' => ['assessment_bills', ['paid_at' => '2099-01-01']];
        yield 'future allocation' => ['assessment_bill_items', ['settled_at' => '2099-01-01']];
        yield 'legacy' => ['assessment_participants', ['metadata' => null]];
        yield 'revoked' => ['assessment_participants', ['revoked_at' => '2026-01-01']];
        yield 'finalized' => ['assessment_participants', ['finalized_at' => '2026-01-01']];
        yield 'void' => ['assessment_participants', ['assessment_status' => 'VOID']];
        yield 'profile' => ['participants', ['full_name' => '']];
        yield 'deleted' => ['participants', ['deleted_at' => '2026-01-01']];
        yield 'identity' => ['identity_verifications', ['outcome' => 'pending']];
        yield 'new evidence' => ['identity_evidence', ['updated_at' => '2099-01-01']];
        yield 'consent' => ['consent_records', ['status' => 'declined', 'consented_at' => null]];
        yield 'bad snapshot' => ['assessment_charges', ['price_snapshot' => '{}']];
    }

    public function test_missing_entitlements_are_created_only_for_purchased_tests(): void
    {
        DB::table('assessment_entitlements')->delete();
        $this->assertSame(['dass21', 'ist'], $this->activate());
        $this->assertDatabaseCount('assessment_entitlements', 2);
    }

    public function test_one_incomplete_member_does_not_block_others_and_can_retry_without_rebilling(): void
    {
        $other = $this->pending(['organization' => $this->f['organization']]);
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->f['bill']]);
        DB::table('assessment_bills')->where('id', $this->f['bill'])->update(['amount' => 200, 'item_count' => 2]);
        DB::table('consent_records')->where('participant_id', $other['participant'])->update(['status' => 'declined']);
        $this->assertSame([], $this->activate($other));
        $this->assertSame(['dass21', 'ist'], $this->activate());
        DB::table('consent_records')->where('participant_id', $other['participant'])->update(['status' => 'accepted']);
        $this->assertSame(['dass21', 'ist'], $this->activate($other));
        $this->assertDatabaseCount('outbox_messages', 2);
        $this->assertDatabaseCount('assessment_bill_items', 2);
    }

    public function test_dass_decline_does_not_block_main_test_and_later_consent_does_not_duplicate_notification(): void
    {
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
        $this->assertSame(['ist'], $this->activate());
        $this->assertDatabaseMissing('assessment_entitlements', ['test_type' => 'dass21', 'status' => 'ready']);
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'accepted']);
        $this->assertSame(['dass21'], $this->activate());
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_paid_previous_attempt_does_not_activate_next_unpaid_attempt(): void
    {
        $other = $this->pending($this->f);
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['settled_at' => null]);
        $this->assertSame(['dass21', 'ist'], $this->activate());
        $this->assertSame([], $this->activate($other));
        $this->assertSame([], $this->activate([...$this->f, 'attempt' => $other['attempt'], 'participant' => 99999]));
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_failure_after_entitlement_save_rolls_back_even_when_outer_caller_catches_it(): void
    {
        AssessmentEntitlement::saved(function (): void {
            throw new RuntimeException('synthetic crash');
        });
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                try {
                    $this->activate();
                    $this->fail('Expected synthetic crash');
                } catch (RuntimeException $e) {
                    $this->assertSame('synthetic crash', $e->getMessage());
                }
            });
        } finally {
            AssessmentEntitlement::flushEventListeners();
        }
        $this->assertDatabaseHas('assessment_entitlements', ['id' => $this->f['entitlement'], 'status' => 'locked', 'ready_at' => null]);
        $this->assertDatabaseHas('assessment_participants', ['id' => $this->f['attempt'], 'assessment_status' => 'PROVISIONED']);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_service_context_is_required(): void
    {
        $this->expectException(LogicException::class);
        app(ActivateSettledAssessment::class)->execute(new AssessmentPrincipal(
            $this->f['participant'], $this->f['organization'], $this->f['attempt']));
    }

    public function test_zero_price_needs_explicit_free_settlement(): void
    {
        DB::table('assessment_bill_items')->delete();
        $charge = AssessmentCharge::findOrFail($this->f['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['baseAmount'] = $snapshot['amount'] = 0;
        $charge->update(['base_amount' => 0, 'amount' => 0, 'price_snapshot' => $snapshot]);
        $this->assertSame([], $this->activate());
        $charge->update(['free_settled_at' => now()]);
        $this->assertSame(['dass21', 'ist'], $this->activate());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_outbox_failure_rolls_back_and_retry_can_enqueue_once(): void
    {
        $failOnce = true;
        DB::listen(function (QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'outbox_messages')) {
                $failOnce = false;
                throw new RuntimeException('synthetic outbox crash');
            }
        });
        app(RlsContextRunner::class)->runAsService(function (): void {
            try {
                $this->activate();
                $this->fail('Expected outbox crash');
            } catch (RuntimeException $e) {
                $this->assertSame('synthetic outbox crash', $e->getMessage());
            }
            $this->assertDatabaseHas('assessment_participants', ['id' => $this->f['attempt'], 'assessment_status' => 'PROVISIONED']);
            $this->assertDatabaseHas('assessment_entitlements', ['id' => $this->f['entitlement'], 'status' => 'locked']);
            $this->assertDatabaseCount('outbox_messages', 0);
        });
        $this->assertSame(['dass21', 'ist'], $this->activate());
        $this->assertSame([], $this->activate());
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_activation_does_not_rewind_started_tests_or_dispatch_legacy_notifications(): void
    {
        $this->activate();
        DB::table('assessment_participants')->update(['assessment_status' => 'IN_PROGRESS']);
        DB::table('assessment_entitlements')->update(['status' => 'in_progress', 'started_at' => now()]);
        $this->assertSame([], $this->activate());
        $this->assertDatabaseHas('assessment_entitlements', ['status' => 'in_progress']);
        $this->assertSame(0, app(DispatchNotificationOutbox::class)->handle());
        $this->assertDatabaseHas('outbox_messages', ['topic' => 'assessment.activation', 'status' => 'pending']);
    }

    public function test_legacy_ready_entitlement_cannot_activate_unpaid_attempt(): void
    {
        DB::table('entitlements')->insert(['participant_id' => $this->f['participant'], 'test_type' => 'ist', 'status' => 'ready', 'ready_at' => now()]);
        DB::table('assessment_bill_items')->update(['settled_at' => null]);
        $this->assertSame([], $this->activate());
        $this->assertDatabaseHas('assessment_entitlements', ['status' => 'locked']);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertDatabaseHas('entitlements', ['status' => 'ready']);
    }

    public function test_foreign_persisted_scope_cannot_activate_an_attempt(): void
    {
        $other = $this->pending();
        foreach (['participant', 'organization', 'attempt'] as $field) {
            $this->assertSame([], $this->activate([...$this->f, $field => $other[$field]]));
        }
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('assessment_entitlements')->where('status', 'ready')->count());
    }
}
