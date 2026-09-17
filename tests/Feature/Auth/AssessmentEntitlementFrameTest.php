<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrerequisiteFrame;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;
use Tests\Support\AssessmentEntitlementBlockedStates;

final class AssessmentEntitlementFrameTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    private CarbonImmutable $asOf;

    private AssessmentPrerequisiteFrame $frame;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00 UTC'));
        $this->asOf = CarbonImmutable::instance(now());
        $this->fixture = Fixture::create();
        $this->frame = new AssessmentPrerequisiteFrame($this->asOf, 'UTC', ConsentDocument::for('psychotest'), ConsentDocument::for('dass'));
    }

    private function principal(?array $fixture = null): AssessmentPrincipal
    {
        $fixture ??= $this->fixture;

        return new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']);
    }

    private function ready(?array $fixture = null, string $type = 'ist', ?AssessmentPrerequisiteFrame $frame = null): int
    {
        return app(RlsContextRunner::class)->runAsService(fn (): int => app(AssessmentEntitlementGate::class)
            ->assertReadyAt($this->principal($fixture), $type, $frame ?? $this->frame)->id);
    }

    #[DataProviderExternal(AssessmentEntitlementBlockedStates::class, 'cases')]
    public function test_explicit_gate_retains_every_existing_denial(string $table, array $values): void
    {
        DB::table($table)->update($values);
        $this->expectException(EntitlementLocked::class);
        $this->ready();
    }

    #[DataProvider('terminalStates')]
    public function test_captured_frame_never_authorizes_stale_or_terminal_state(string $table, array $values): void
    {
        DB::table($table)->update($values);
        try {
            $this->ready();
            $this->fail('Captured frame is not authority over current persisted state.');
        } catch (EntitlementLocked $exception) {
            $this->assertSame('', $exception->getMessage());
        }
    }

    public static function terminalStates(): iterable
    {
        yield 'void' => ['assessment_participants', ['assessment_status' => 'VOID']];
        yield 'completed' => ['assessment_participants', ['assessment_status' => 'COMPLETED']];
        yield 'finalized' => ['assessment_participants', ['finalized_at' => '2026-09-04 10:00:00']];
        yield 'ready but started' => ['assessment_entitlements', ['started_at' => '2026-09-04 10:00:00']];
        yield 'ready but completed' => ['assessment_entitlements', ['completed_at' => '2026-09-04 10:00:00']];
    }

    public function test_foreign_scope_is_reloaded_despite_a_valid_frame(): void
    {
        $other = Fixture::create();
        foreach (['organization', 'participant', 'attempt'] as $field) {
            try {
                $this->ready([...$this->fixture, $field => $other[$field]]);
                $this->fail('Foreign principal accepted by explicit gate.');
            } catch (EntitlementLocked $exception) {
                $this->assertSame('', $exception->getMessage());
            }
        }
    }

    #[DataProvider('offsets')]
    public function test_ready_at_is_bounded_by_frame_even_when_global_clock_is_later(int $offset): void
    {
        DB::table('assessment_entitlements')->update(['ready_at' => $this->asOf->addSeconds($offset)]);
        $this->travelTo($this->asOf->addDay());
        if ($offset > 0) {
            $this->expectException(EntitlementLocked::class);
        }
        $this->assertSame($this->fixture['entitlement'], $this->ready());
    }

    public static function offsets(): iterable
    {
        yield 'before' => [-1];
        yield 'exact' => [0];
        yield 'future' => [1];
    }

    public function test_payment_and_prerequisites_share_frame_after_clock_and_config_drift(): void
    {
        $before = $this->rows();
        $this->afterEntitlementRead(function (): void {
            config()->set('consent.documents', null);
            $this->travelTo($this->asOf->subDay());
        }, function (): void {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $context = app(RlsContextRunner::class)->current();
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $this->assertSame($this->fixture['entitlement'], app(AssessmentEntitlementGate::class)
                        ->assertReadyAt($this->principal(), 'ist', $this->frame)->id);
                    foreach (DB::getQueryLog() as $query) {
                        $this->assertStringStartsWith('select', $query['query']);
                        $this->assertStringNotContainsString('for update', $query['query']);
                    }
                } finally {
                    DB::disableQueryLog();
                }
                $this->assertSame($context, app(RlsContextRunner::class)->current());
            });
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertNull(config('consent.documents'));
            $this->assertTrue(now()->equalTo($this->asOf->subDay()));
        });
        $this->assertSame($before, $this->rows());
    }

    #[DataProvider('futureComponents')]
    public function test_forward_clock_drift_cannot_admit_future_payment_or_prerequisites(string $table, string $column): void
    {
        DB::table($table)->update([$column => $this->asOf->addSecond()]);
        $this->afterEntitlementRead(fn () => $this->travelTo($this->asOf->addDay()), function (): void {
            try {
                $this->ready();
                $this->fail('Clock drift admitted evidence after the frame cutoff.');
            } catch (EntitlementLocked $exception) {
                $this->assertSame('', $exception->getMessage());
            }
        });
    }

    public static function futureComponents(): iterable
    {
        yield 'paid bill' => ['assessment_bills', 'paid_at'];
        yield 'settled item' => ['assessment_bill_items', 'settled_at'];
        yield 'consent' => ['consent_records', 'consented_at'];
        yield 'identity' => ['identity_verifications', 'checked_at'];
    }

    public function test_same_version_changed_text_does_not_satisfy_the_new_captured_document(): void
    {
        $document = ConsentDocument::for('psychotest');
        config()->set('consent.documents.psychotest.text', $document->text.' Changed server text.');
        $changed = new AssessmentPrerequisiteFrame($this->asOf, 'UTC', ConsentDocument::for('psychotest'));
        $this->assertSame($this->fixture['entitlement'], $this->ready());
        $this->expectException(EntitlementLocked::class);
        $this->ready(frame: $changed);
    }

    public function test_dass_consent_is_required_only_for_dass_snapshot_type(): void
    {
        $dass = Fixture::create('dass21', $this->fixture);
        $this->assertSame($dass['entitlement'], $this->ready($dass, 'dass21'));
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
        $mainOnly = new AssessmentPrerequisiteFrame($this->asOf, 'UTC', ConsentDocument::for('psychotest'));
        $this->assertSame($this->fixture['entitlement'], $this->ready(frame: $mainOnly));
        $this->expectException(EntitlementLocked::class);
        $this->ready($dass, 'dass21');
    }

    #[DataProvider('deniedRoles')]
    public function test_frame_does_not_bypass_service_guard_or_query_before_denial(?string $role): void
    {
        $read = function (): void {
            $context = app(RlsContextRunner::class)->current();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                try {
                    app(AssessmentEntitlementGate::class)->assertReadyAt($this->principal(), 'ist', $this->frame);
                    $this->fail('Captured documents are not authentication.');
                } catch (LogicException $exception) {
                    $this->assertSame('Assessment gate requires service RLS context.', $exception->getMessage());
                }
                $this->assertSame([], DB::getQueryLog());
                $this->assertSame($context, app(RlsContextRunner::class)->current());
            } finally {
                DB::disableQueryLog();
            }
        };
        if ($role === null) {
            $read();
        } else {
            app(RlsContextRunner::class)->run(new RlsContext($role, $this->fixture['organization'], $this->fixture['participant']), $read);
        }
    }

    public static function deniedRoles(): iterable
    {
        foreach ([null, 'super_admin', 'branch_admin', 'staff', 'psychologist', 'participant'] as $role) {
            yield $role ?? 'none' => [$role];
        }
    }

    public function test_legacy_preserves_denial_before_invalid_config_and_propagates_config_after_valid_evidence(): void
    {
        config()->set('consent.documents', null);
        DB::table('assessment_bills')->update(['status' => 'pending']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            try {
                app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist');
                $this->fail('Unsettled evidence must deny before invalid document config.');
            } catch (EntitlementLocked $exception) {
                $this->assertSame('', $exception->getMessage());
            }
        });
        DB::table('assessment_bills')->update(['status' => 'paid']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or invalid consent document [psychotest].');
        app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist'));
    }

    private function afterEntitlementRead(callable $drift, callable $evaluate): void
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $hit = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$hit, $drift): void {
            if (! $hit && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "assessment_entitlements"')) {
                $hit = true;
                $drift();
            }
        });
        try {
            $evaluate();
            $this->assertTrue($hit);
        } finally {
            $connection->setEventDispatcher($original);
            $this->travelTo($this->asOf);
        }
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['assessment_participants', 'assessment_charges', 'assessment_entitlements', 'assessment_bills',
            'assessment_bill_items', 'participants', 'consent_records', 'identity_verifications', 'identity_evidence', 'audit_logs', 'outbox_messages'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
