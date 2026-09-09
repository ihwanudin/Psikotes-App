<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Identity\StoreIdentityEvidence;
use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\InvalidCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Payments\FinalizeAssessmentBill;
use App\Contracts\IdentityMatcher;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSessionSelector;
use App\Data\Payments\PaymentEvent;
use App\Enums\CheckoutHandoffIntent;
use App\Enums\PaymentStatus;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Identity\ManualReviewIdentityMatcher;
use App\Services\Payments\AssessmentPriceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative P14a3 lifecycle serialization evidence. */
final class CheckoutSessionLifecycleConcurrencyTest extends TestCase
{
    /** @var array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string,session:int,selector:string,csrf:string} */
    private array $fixture;

    /** @var array{bill:int,method:int,reference:string,gateway:string}|null */
    private ?array $payment = null;

    protected function setUp(): void
    {
        parent::setUp();
        $identity = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->configure();
        $graph = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($graph['client']),
                $graph['attemptPublicId'], $graph['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)),
                CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            throw new RuntimeException('Synthetic handoff bearer unavailable.');
        }
        $established = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $session = app(RlsContextRunner::class)->runAsService(
            fn (): mixed => CheckoutSession::query()->where('public_id', $established->sessionPublicId)->value('id'),
        );
        if (! is_int($session)) {
            throw new RuntimeException('Synthetic session unavailable.');
        }
        $this->fixture = [...$graph, 'session' => $session,
            'selector' => $established->rawSelector(), 'csrf' => $established->rawCsrfToken()];
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->delete();
            DB::table('assessment_entitlements')->where('assessment_participant_id', $this->fixture['attempt'])->delete();
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $this->fixture['participant'])->delete();
            }
            if ($this->payment !== null) {
                DB::table('audit_logs')->where('subject_type', AssessmentBill::class)
                    ->where('subject_id', (string) $this->payment['bill'])->delete();
                DB::table('assessment_bill_items')->where('bill_id', $this->payment['bill'])->delete();
                DB::table('assessment_bills')->where('id', $this->payment['bill'])->delete();
                DB::table('assessment_charges')->where('assessment_participant_id', $this->fixture['attempt'])->delete();
                DB::table('payment_methods')->where('id', $this->payment['method'])->delete();
            }
            DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
                ->where('subject_id', (string) $this->fixture['attempt'])->delete();
            DB::table('checkout_sessions')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('checkout_handoffs')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('integration_sources')->where('integration_client_id', $this->fixture['client'])->delete();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
        });
        parent::tearDown();
    }

    public function test_two_logout_processes_have_one_transition_and_one_generic_replay(): void
    {
        $results = $this->raceTwo(
            fn (): array => $this->logoutDescriptor(),
            fn (): array => $this->logoutDescriptor(),
        );

        $this->assertCount(1, array_filter($results, fn (array $row): bool => ($row['loggedOut'] ?? false) === true));
        $this->assertCount(1, array_filter($results, fn (array $row): bool => ($row['invalid'] ?? false) === true));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $session = DB::table('checkout_sessions')->where('id', $this->fixture['session'])->sole();
            $this->assertSame('REVOKED', $session->status);
            $this->assertSame('LOGOUT', $session->revocation_reason);
            $this->assertNull($session->active_marker);
            $this->assertSame(1, $this->auditCount('checkout_session.revoked'));
        });
    }

    public function test_hydrate_waiting_behind_scope_revocation_fails_and_terminalizes_once(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->hydrateDescriptor()]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                DB::table('integration_clients')->where('id', $this->fixture['client'])->lockForUpdate()
                    ->update(['enabled' => false]);
            });
            $result = $this->workerResults($workers)[0];
        } finally {
            $this->stopWorkers($workers);
        }

        $this->assertTrue($result['invalid'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertFalse($result['hydrated'] ?? true);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $session = DB::table('checkout_sessions')->where('id', $this->fixture['session'])->sole();
            $this->assertSame('REVOKED', $session->status);
            $this->assertSame('SCOPE_REVOKED', $session->revocation_reason);
            $this->assertSame(1, $this->auditCount('checkout_session.revoked'));
        });
    }

    public function test_profile_waiting_behind_recovery_commit_cannot_project_old_generation(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->profileDescriptor()]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                $this->recover();
            });
            $this->assertSame([['projected' => false, 'invalid' => true]], $this->workerResults($workers));
        } finally {
            $this->stopWorkers($workers);
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $session = CheckoutSession::query()->findOrFail($this->fixture['session']);
            $this->assertSame('RECOVERY_REISSUED', $session->revocation_reason);
            $this->assertSame(2, DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->count());
        });
    }

    public function test_profile_waiting_behind_scope_revoke_cannot_project_and_terminalizes_once(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->profileDescriptor()]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                DB::table('integration_clients')->where('id', $this->fixture['client'])->lockForUpdate()->update(['enabled' => false]);
            });
            $this->assertSame([['projected' => false, 'invalid' => true]], $this->workerResults($workers));
        } finally {
            $this->stopWorkers($workers);
        }
        $this->assertSame(['projected' => false, 'invalid' => true], $this->profileDescriptor());
        app(RlsContextRunner::class)->runAsService(function (): void {
            $session = CheckoutSession::query()->findOrFail($this->fixture['session']);
            $this->assertSame('SCOPE_REVOKED', $session->revocation_reason);
            $this->assertSame(1, $this->auditCount('checkout_session.revoked'));
        });
    }

    public function test_profile_waiting_behind_rolled_back_recovery_reads_original_generation(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->profileDescriptor()]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            try {
                app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                    DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backendId);
                    $this->recover();
                    throw new RuntimeException('Synthetic recovery rollback.');
                });
                $this->fail('Synthetic rollback did not propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic recovery rollback.', $exception->getMessage());
            }
            $this->assertSame([['projected' => true, 'invalid' => false]], $this->workerResults($workers));
        } finally {
            $this->stopWorkers($workers);
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('ACTIVE', CheckoutSession::query()->findOrFail($this->fixture['session'])->status);
            $this->assertSame(1, DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(0, $this->auditCount('checkout_session.revoked'));
        });
    }

    public function test_profile_projection_holds_canonical_lock_until_mapping_before_recovery(): void
    {
        $workers = $this->startWorkers([function (): array {
            $this->configure();
            app(RlsContextRunner::class)->runAsService(fn () => $this->recover());

            return ['recovered' => true];
        }]);
        $armed = true;
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            DB::listen(function (QueryExecuted $query) use (&$armed, $workers, $backendId): void {
                if ($armed && str_contains($query->sql, 'from "branches"') && str_contains($query->sql, 'for update')) {
                    $armed = false;
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backendId);
                }
            });
            $this->assertSame(['projected' => true, 'invalid' => false], $this->profileDescriptor());
            $this->assertFalse($armed, 'Profile must hold canonical organization lock.');
            $this->assertSame([['recovered' => true]], $this->workerResults($workers));
        } finally {
            $armed = false;
            $this->stopWorkers($workers);
        }
        $this->assertSame(['projected' => false, 'invalid' => true], $this->profileDescriptor());
    }

    public function test_payment_projection_waiting_behind_finalizer_reads_only_committed_paid_allocation(): void
    {
        $this->preparePayment();
        $workers = $this->startWorkers([fn (): array => $this->paymentDescriptor()]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                $this->finalizePayment();
            });
            $this->assertSame([['state' => 'paid', 'amount' => 100]], $this->workerResults($workers));
        } finally {
            $this->stopWorkers($workers);
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(0, DB::table('assessment_entitlements')->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(0, DB::table('outbox_messages')->where('aggregate_id', (string) $this->fixture['attempt'])->count());
        });
    }

    public function test_payment_projection_waiting_behind_finalizer_rollback_does_not_read_partial_paid(): void
    {
        $this->preparePayment();
        $workers = $this->startWorkers([fn (): array => $this->paymentDescriptor()]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            try {
                app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                    DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backendId);
                    $this->finalizePayment();
                    throw new RuntimeException('Synthetic finalizer rollback.');
                });
                $this->fail('Finalizer rollback did not propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic finalizer rollback.', $exception->getMessage());
            }
            $this->assertSame([['state' => 'pending', 'amount' => 100]], $this->workerResults($workers));
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_payment_projection_finishes_before_waiting_finalizer_without_bill_lock_inversion(): void
    {
        $this->preparePayment();
        $workers = $this->startWorkers([function (): array {
            $this->finalizePayment();

            return ['settled' => true];
        }]);
        $armed = true;
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            DB::listen(function (QueryExecuted $query) use (&$armed, $workers, $backendId): void {
                if ($armed && str_contains($query->sql, 'from "branches"') && str_contains($query->sql, 'for update')) {
                    $armed = false;
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backendId);
                }
            });
            $this->assertSame(['state' => 'pending', 'amount' => 100], $this->paymentDescriptor());
            $this->assertFalse($armed);
            $this->assertSame([['settled' => true]], $this->workerResults($workers));
        } finally {
            $armed = false;
            $this->stopWorkers($workers);
        }
        $this->assertSame(['state' => 'paid', 'amount' => 100], $this->paymentDescriptor());
    }

    public function test_summary_waits_for_recovery_and_scope_revoke_before_any_projection(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->summaryDescriptor()]);
        try {
            $pid = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $pid): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $this->recover();
            });
            $this->assertSame([['invalid' => true]], $this->workerResults($workers));
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_summary_scope_revocation_wait_is_observed_and_terminalized_once(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->summaryDescriptor()]);
        try {
            $pid = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $pid): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                DB::table('integration_clients')->where('id', $this->fixture['client'])->update(['enabled' => false]);
            });
            $this->assertSame([['invalid' => true]], $this->workerResults($workers));
            $this->assertSame(['invalid' => true], $this->summaryDescriptor());
            app(RlsContextRunner::class)->runAsService(fn () => $this->assertSame(1, $this->auditCount('checkout_session.revoked')));
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_summary_finalizer_commit_is_paid_but_partial_profile_still_locked(): void
    {
        $this->preparePayment();
        $workers = $this->startWorkers([fn (): array => $this->summaryDescriptor()]);
        try {
            $pid = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $pid): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $this->finalizePayment();
            });
            $this->assertSummary($this->workerResults($workers)[0], 'paid', 'locked');
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_summary_finalizer_rollback_cannot_leak_paid_allocation_or_activation(): void
    {
        $this->preparePayment();
        $workers = $this->startWorkers([fn (): array => $this->summaryDescriptor()]);
        try {
            $pid = $this->workerBackendIds($workers)[0];
            try {
                app(RlsContextRunner::class)->runAsService(function () use ($workers, $pid): void {
                    DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    $this->finalizePayment();
                    throw new RuntimeException('Synthetic summary finalizer rollback');
                });
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic summary finalizer rollback', $error->getMessage());
            }
            $this->assertSummary($this->workerResults($workers)[0], 'pending', 'locked');
            app(RlsContextRunner::class)->runAsService(function (): void {
                $this->assertNull(DB::table('assessment_bill_items')->where('bill_id', $this->payment['bill'])->value('settled_at'));
                $this->assertSame(0, DB::table('outbox_messages')->where('aggregate_id', (string) $this->fixture['attempt'])->count());
                $this->assertSame(0, DB::table('audit_logs')->where('subject_type', AssessmentBill::class)->where('subject_id', (string) $this->payment['bill'])->count());
            });
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_summary_reader_first_finishes_before_finalizer_without_bill_lock_inversion(): void
    {
        $this->preparePayment();
        $workers = $this->startWorkers([function (): array {
            $this->finalizePayment();

            return ['settled' => true];
        }]);
        try {
            $pid = $this->workerBackendIds($workers)[0];
            $this->duringSummaryQuery('from "participants"', function () use ($workers, $pid): void {
                fwrite($workers[0]['socket'], "go\n");
                $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
            }, fn () => $this->assertSummary($this->summaryDescriptor(), 'pending', 'locked'));
            $this->assertSame([['settled' => true]], $this->workerResults($workers));
            $this->assertSummary($this->summaryDescriptor(), 'paid', 'locked');
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_summary_actual_identity_reader_first_keeps_complete_old_revision(): void
    {
        $this->withSummaryIdentity(function (): void {
            $workers = $this->startWorkers([fn (): array => ['outcome' => $this->replaceSummaryIdentity()]]);
            try {
                $pid = $this->workerBackendIds($workers)[0];
                $this->duringSummaryQuery('from "identity_verifications"', function () use ($workers, $pid): void {
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                }, fn () => $this->assertSummary($this->summaryDescriptor(), 'paid', 'ready', 'accepted'));
                $this->assertSame([['outcome' => 'pending']], $this->workerResults($workers));
                $this->assertSummary($this->summaryDescriptor(), 'paid', 'locked', 'accepted');
            } finally {
                $this->stopWorkers($workers);
            }
        });
    }

    public function test_summary_actual_identity_writer_first_observes_only_committed_pending_revision(): void
    {
        $this->withSummaryIdentity(function (): void {
            $workers = $this->startWorkers([fn (): array => $this->summaryDescriptor()]);
            try {
                $pid = $this->workerBackendIds($workers)[0];
                $this->duringSummaryQuery('from "participants"', function () use ($workers, $pid): void {
                    fwrite($workers[0]['socket'], "go\n");
                    $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                }, fn () => $this->assertSame('pending', $this->replaceSummaryIdentity()));
                $this->assertSummary($this->workerResults($workers)[0], 'paid', 'locked', 'accepted');
            } finally {
                $this->stopWorkers($workers);
            }
        });
    }

    public function test_summary_late_failure_rolls_back_observed_idle_update_with_clean_context(): void
    {
        $this->withSummaryIdentity(function (): void {
            $before = app(RlsContextRunner::class)->runAsService(function (): string {
                DB::table('checkout_sessions')->where('id', $this->fixture['session'])->update([
                    'idle_expires_at' => DB::raw("clock_timestamp() + interval '5 minutes'")]);

                return DB::table('checkout_sessions')->where('id', $this->fixture['session'])->get()->toJson();
            });
            try {
                $this->duringSummaryQuery('from "assessment_entitlements"', function () use ($before): void {
                    $this->assertSame('service', app(RlsContextRunner::class)->current()?->role);
                    $this->assertSame(1, DB::transactionLevel());
                    $this->assertNotSame($before, DB::table('checkout_sessions')->where('id', $this->fixture['session'])->get()->toJson());
                    throw new RuntimeException('Synthetic summary late failure');
                }, fn () => $this->summaryDescriptor());
                $this->fail('Late failure was swallowed.');
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic summary late failure', $error->getMessage());
            }
            $this->assertSame($before, app(RlsContextRunner::class)->runAsService(fn (): string => DB::table('checkout_sessions')->where('id', $this->fixture['session'])->get()->toJson()));
        });
    }

    public function test_summary_configured_utc_preserves_offset_payment_and_identity_instants(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertContains(DB::selectOne('SHOW TIMEZONE')->TimeZone, ['UTC', 'Etc/UTC', '+00:00']);
        $this->withSummaryIdentity(function (): void {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $at = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS at')->at)->subMinute();
                DB::table('assessment_bills')->where('id', $this->payment['bill'])->update(['paid_at' => $at->setTimezone('+07:00')->format('Y-m-d H:i:sP')]);
                DB::table('assessment_bill_items')->where('bill_id', $this->payment['bill'])->update(['settled_at' => $at->setTimezone('-04:00')->format('Y-m-d H:i:sP')]);
            });
            $this->assertSummary($this->summaryDescriptor(), 'paid', 'ready', 'accepted');
        });
    }

    public function test_summary_collective_finalizer_wait_exposes_only_own_amount_and_no_foreign_profile(): void
    {
        $this->preparePayment();
        [$otherParticipant, $otherAttempt, $otherCharge] = app(RlsContextRunner::class)->runAsService(function (): array {
            $item = (array) DB::table('assessment_bill_items')->where('bill_id', $this->payment['bill'])->sole();
            DB::table('assessment_bill_items')->where('bill_id', $this->payment['bill'])->delete();
            DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update([
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
            DB::table('assessment_charges')->where('assessment_participant_id', $this->fixture['attempt'])->update(['payer_type' => 'organization']);
            DB::table('assessment_bills')->where('id', $this->payment['bill'])->update(['payer_type' => 'organization',
                'payer_participant_id' => null, 'amount' => 200, 'item_count' => 2, 'invoice_url' => 'https://synthetic.invalid/PRIVATE_INVOICE']);
            DB::table('assessment_bill_items')->insert(array_replace($item, ['payer_type' => 'organization', 'payer_participant_id' => null]));
            $otherParticipant = DB::table('participants')->insertGetId(['branch_id' => $this->fixture['organization'],
                'referral_branch_id' => $this->fixture['organization'], 'referral_source' => 'manual', 'full_name' => 'PRIVATE_OTHER_MEMBER']);
            $other = AssessmentParticipant::findOrFail($this->fixture['attempt'])->replicate();
            $key = (string) Str::ulid();
            $timestamp = now();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $key, 'participant_id' => $otherParticipant,
                'organization_id' => $this->fixture['organization'], 'package_id' => $this->fixture['package'],
                'origin' => 'INTEGRATED', 'intended_field_snapshot' => null,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $other->fill(['participant_id' => $otherParticipant, 'assessment_case_id' => $case,
                'assessment_attempt_id' => $key,
                'external_candidate_id' => $key, 'idempotency_key' => $key,
                'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
                'created_at' => $timestamp, 'updated_at' => $timestamp])->save();
            $charge = AssessmentCharge::where('assessment_participant_id', $this->fixture['attempt'])->sole()->replicate();
            $charge->fill(['assessment_participant_id' => $other->id, 'participant_id' => $otherParticipant])->save();
            unset($item['id']);
            DB::table('assessment_bill_items')->insert(array_replace($item, ['charge_id' => $charge->id, 'participant_id' => $otherParticipant,
                'payer_type' => 'organization', 'payer_participant_id' => null]));

            return [$otherParticipant, $other->id, $charge->id];
        });
        $workers = $this->startWorkers([fn (): array => $this->summaryDescriptor()]);
        try {
            $pid = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $pid): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertSummaryBlockedBy($pid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $result = app(FinalizeAssessmentBill::class)->execute(new PaymentEvent('summary-collective', $this->payment['gateway'],
                    $this->payment['reference'], PaymentStatus::Paid, now()->startOfSecond(), 200, 'IDR'));
                $this->assertSame(['decision' => 'settled', 'allocationCount' => 2, 'activatedAttemptCount' => 0], $result);
            });
            $summary = $this->workerResults($workers)[0];
            $this->assertSummary($summary, 'paid', 'locked');
            $this->assertSame('organization', $summary['payment']['payer']);
            $this->assertSame('P14a3 Synthetic', $summary['payment']['organizationName']);
            $this->assertSame(['payer', 'state', 'amountIdr', 'amountSource', 'consultationRequested', 'actionAvailable',
                'action', 'organizationName'], array_keys($summary['payment']));
            $this->assertNull($summary['payment']['action']);
        } finally {
            $this->stopWorkers($workers);
            app(RlsContextRunner::class)->runAsService(function () use ($otherCharge): void {
                DB::table('assessment_bill_items')->where('charge_id', $otherCharge)->delete();
                DB::table('assessment_charges')->where('id', $otherCharge)->delete();
            });
        }
    }

    private function summaryDescriptor(): array
    {
        $this->configure();
        try {
            return app(CheckoutSessionLifecycle::class)->readSummary(new CheckoutSessionMutationCredentials(
                $this->fixture['selector'], $this->fixture['csrf']))->toArray();
        } catch (InvalidCheckoutSession) {
            return ['invalid' => true];
        } finally {
            if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
                throw new RuntimeException('Summary leaked context or transaction.');
            }
        }
    }

    private function assertSummary(array $data, string $payment, string $access, string $dass = 'required'): void
    {
        $this->assertSame(['contractVersion', 'sourceName', 'branchName', 'packageName', 'packageSource', 'attemptLabel',
            'profile', 'identityMessage', 'payment', 'access', 'consents'], array_keys($data));
        $this->assertCount(7, $data['profile']);
        $this->assertSame('P14a3 Synthetic', $data['branchName']);
        $this->assertSame('P14a3 Synthetic', $data['packageName']);
        $this->assertSame('charge_snapshot', $data['packageSource']);
        $this->assertSame(100, $data['payment']['amountIdr']);
        $this->assertSame($payment, $data['payment']['state']);
        $this->assertSame($access, $data['access']['state']);
        $this->assertSame([
            ['testType' => 'dass21', 'state' => $access],
            ['testType' => 'ist', 'state' => $access],
        ], $data['access']['tests']);
        $this->assertFalse($data['payment']['actionAvailable']);
        $this->assertFalse($data['access']['startAvailable']);
        $this->assertSame($dass, $data['consents']['dass']['state']);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach ([$this->fixture['selector'], $this->fixture['csrf'], $this->fixture['attemptPublicId'],
            $this->fixture['sourceSystem'], 'synthetic-only', 'PRIVATE_OTHER_MEMBER', 'PRIVATE_INVOICE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    private function assertSummaryBlockedBy(int $pid, int $blocker): void
    {
        $this->assertWorkerWaitsOnLock($pid);
        $row = DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS exact_blocker', [$blocker, $pid]);
        $this->assertTrue($row->exact_blocker);
    }

    private function duringSummaryQuery(string $needle, callable $barrier, callable $operation): void
    {
        $connection = DB::connection();
        $previous = $connection->getEventDispatcher();
        $events = clone $previous;
        $connection->setEventDispatcher($events);
        $armed = true;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $needle, $barrier): void {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, $needle)) {
                $armed = false;
                $barrier();
            }
        });
        try {
            $operation();
            $this->assertFalse($armed, 'Expected query barrier was not reached.');
        } finally {
            $connection->setEventDispatcher($previous);
        }
    }

    private function withSummaryIdentity(callable $test): void
    {
        $disk = 'smry-'.strtolower((string) Str::ulid());
        $previousDisk = config('identity.disk');
        $previousMatcher = app(IdentityMatcher::class);
        $fake = Storage::fake($disk);
        $this->assertStringStartsWith('/workspace/storage/framework/testing/disks/smry-', $fake->path(''));
        config()->set('identity.disk', $disk);
        app()->instance(IdentityMatcher::class, new ManualReviewIdentityMatcher);
        try {
            $this->preparePayment();
            $this->replaceSummaryIdentity();
            app(RlsContextRunner::class)->runAsService(function (): void {
                $at = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS at')->at)->subSeconds(5);
                DB::table('participants')->where('id', $this->fixture['participant'])->update([
                    'gender' => 'male', 'birth_date' => '2000-01-01', 'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM']);
                foreach (['psychotest', 'dass'] as $type) {
                    $document = ConsentDocument::for($type);
                    DB::table('consent_records')->insert(['participant_id' => $this->fixture['participant'],
                        'consent_type' => $type, 'status' => 'accepted', 'document_version' => $document->version,
                        'document_hash' => $document->hash, 'consented_at' => $at]);
                }
                DB::table('identity_verifications')->where('participant_id', $this->fixture['participant'])->update([
                    'matcher' => 'synthetic-old-match', 'outcome' => 'match', 'checked_at' => $at]);
                DB::table('identity_evidence')->where('participant_id', $this->fixture['participant'])->update(['updated_at' => $at]);
            });
            $result = app(FinalizeAssessmentBill::class)->execute(new PaymentEvent('summary-paid', $this->payment['gateway'],
                $this->payment['reference'], PaymentStatus::Paid, now()->startOfSecond(), 100, 'IDR'));
            $this->assertSame(1, $result['activatedAttemptCount']);
            $this->assertSummary($this->summaryDescriptor(), 'paid', 'ready', 'accepted');
            $test();
        } finally {
            Storage::disk($disk)->deleteDirectory('/');
            Storage::forgetDisk($disk);
            config()->set('identity.disk', $previousDisk);
            app()->instance(IdentityMatcher::class, $previousMatcher);
        }
    }

    private function replaceSummaryIdentity(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        $result = app(StoreIdentityEvidence::class)->handle($this->fixture['participant'],
            UploadedFile::fake()->createWithContent('summary-document.png', $png),
            UploadedFile::fake()->createWithContent('summary-selfie.png', $png));
        if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Identity replacement leaked context.');
        }

        return $result->outcome;
    }

    /** @return array{state:string,amount:int|null} */
    private function paymentDescriptor(): array
    {
        $this->configure();
        try {
            $facts = app(CheckoutSessionLifecycle::class)->readPayment(new CheckoutSessionMutationCredentials(
                $this->fixture['selector'], $this->fixture['csrf'],
            ));

            return ['state' => $facts->state, 'amount' => $facts->amountIdr];
        } finally {
            if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
                throw new RuntimeException('Payment projection leaked context.');
            }
        }
    }

    private function finalizePayment(): void
    {
        if ($this->payment === null) {
            throw new RuntimeException('Missing synthetic bill.');
        }
        $result = app(FinalizeAssessmentBill::class)->execute(new PaymentEvent(
            'synthetic-paid-event', $this->payment['gateway'], $this->payment['reference'],
            PaymentStatus::Paid, now()->startOfSecond(), 100, 'IDR',
        ));
        if ($result['decision'] !== 'settled' || $result['activatedAttemptCount'] !== 0) {
            throw new RuntimeException('Synthetic partial profile finalization was inconsistent.');
        }
    }

    private function preparePayment(): void
    {
        $this->payment = app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->fixture['package']), false);
            $charge = AssessmentCharge::create([
                'assessment_participant_id' => $this->fixture['attempt'], 'organization_id' => $this->fixture['organization'],
                'participant_id' => $this->fixture['participant'], 'package_id' => $this->fixture['package'],
                'payer_type' => 'self', 'base_amount' => 100, 'consultation_amount' => 0, 'consultation_requested' => false,
                'amount' => 100, 'currency' => 'IDR', 'price_snapshot' => $snapshot, 'policy_snapshot' => ['version' => 1],
            ]);
            $method = DB::table('payment_methods')->insertGetId(['code' => 'P14C_'.$key, 'display_name' => 'Synthetic', 'is_active' => false]);
            $reference = 'AB_'.$key;
            $gateway = 'synthetic-'.$key;
            $bill = DB::table('assessment_bills')->insertGetId([
                'organization_id' => $this->fixture['organization'], 'payer_type' => 'self',
                'payer_participant_id' => $this->fixture['participant'], 'public_reference' => $reference,
                'amount' => 100, 'currency' => 'IDR', 'item_count' => 1, 'selection_hash' => hash('sha256', $key),
                'idempotency_key' => $key, 'request_hash' => hash('sha256', $key), 'status' => 'pending',
                'payment_method_id' => $method, 'gateway_ref' => $gateway,
            ]);
            DB::table('assessment_bill_items')->insert([
                'bill_id' => $bill, 'charge_id' => $charge->id, 'organization_id' => $this->fixture['organization'],
                'participant_id' => $this->fixture['participant'], 'payer_type' => 'self',
                'payer_participant_id' => $this->fixture['participant'], 'amount' => 100, 'currency' => 'IDR',
            ]);
            DB::table('branches')->where('id', $this->fixture['organization'])->update(['allowed_payer_types' => '[]']);

            return compact('bill', 'method', 'reference', 'gateway');
        });
    }

    /** @return array{projected:bool,invalid:bool} */
    private function profileDescriptor(): array
    {
        $this->configure();
        try {
            $profile = app(CheckoutSessionLifecycle::class)->readProfile(new CheckoutSessionMutationCredentials(
                $this->fixture['selector'], $this->fixture['csrf'],
            ));
            if ($profile->fullName !== 'P14a3 Synthetic' || array_keys($profile->toArray()) !== ['profile']) {
                throw new RuntimeException('Projection was not the exact synthetic profile.');
            }

            return ['projected' => true, 'invalid' => false];
        } catch (InvalidCheckoutSession) {
            return ['projected' => false, 'invalid' => true];
        } finally {
            if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
                throw new RuntimeException('Profile lifecycle leaked its context or transaction.');
            }
        }
    }

    private function recover(): void
    {
        app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
            IntegrationClient::query()->findOrFail($this->fixture['client']), $this->fixture['attemptPublicId'],
            $this->fixture['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery,
        ));
    }

    /** @return array{loggedOut:bool,invalid:bool} */
    private function logoutDescriptor(): array
    {
        $this->configure();
        try {
            app(CheckoutSessionLifecycle::class)->logout(new CheckoutSessionMutationCredentials(
                $this->fixture['selector'], $this->fixture['csrf'],
            ));

            return ['loggedOut' => true, 'invalid' => false];
        } catch (InvalidCheckoutSession) {
            return ['loggedOut' => false, 'invalid' => true];
        }
    }

    /** @return array{hydrated:bool,invalid:bool} */
    private function hydrateDescriptor(): array
    {
        $this->configure();
        try {
            app(CheckoutSessionLifecycle::class)->hydrate(new CheckoutSessionSelector($this->fixture['selector']));

            return ['hydrated' => true, 'invalid' => false];
        } catch (InvalidCheckoutSession) {
            return ['hydrated' => false, 'invalid' => true];
        }
    }

    private function configure(): void
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
    }

    /** @param callable():array<string,mixed> $first
     * @param  callable():array<string,mixed>  $second
     * @return list<array<string,mixed>>
     */
    private function raceTwo(callable $first, callable $second): array
    {
        $workers = $this->startWorkers([$first, $second]);
        try {
            $backendIds = $this->workerBackendIds($workers);
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendIds): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                foreach ($workers as $index => $worker) {
                    fwrite($worker['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backendIds[$index]);
                }
            });

            return $this->workerResults($workers);
        } finally {
            $this->stopWorkers($workers);
        }
    }

    /** @param list<callable():array<string,mixed>> $callbacks
     * @return list<array{pid:int,socket:resource}>
     */
    private function startWorkers(array $callbacks): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'P14a3 requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        foreach ($callbacks as $callback) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create lifecycle worker.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($workers as $worker) {
                    fclose($worker['socket']);
                }
                stream_set_timeout($pair[1], 20);
                try {
                    $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
                    if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                        throw new RuntimeException('Worker must be runtime non-owner without RLS bypass.');
                    }
                    DB::statement("SET lock_timeout = '12s'");
                    DB::statement("SET statement_timeout = '15s'");
                    fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                    if (fgets($pair[1]) !== "go\n") {
                        throw new RuntimeException('Lifecycle barrier timed out.');
                    }
                    $result = $callback();
                } catch (Throwable $exception) {
                    $result = ['unexpected' => $exception::class, 'message' => $exception->getMessage()];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                fclose($pair[1]);
                DB::disconnect('pgsql');
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 20);
            $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
        }

        return $workers;
    }

    /** @param list<array{pid:int,socket:resource}> $workers
     * @return list<int>
     */
    private function workerBackendIds(array $workers): array
    {
        $ids = [];
        foreach ($workers as $worker) {
            $line = fgets($worker['socket']);
            if (! is_string($line)) {
                throw new RuntimeException('Worker did not report backend identity.');
            }
            $ids[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR)['pid'];
        }

        return $ids;
    }

    private function assertWorkerWaitsOnLock(int $backendId): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type);
    }

    /** @param list<array{pid:int,socket:resource}> $workers
     * @return list<array<string,mixed>>
     */
    private function workerResults(array $workers): array
    {
        $results = [];
        foreach ($workers as $worker) {
            $line = fgets($worker['socket']);
            if (! is_string($line)) {
                throw new RuntimeException('Worker did not report result.');
            }
            $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /** @param list<array{pid:int,socket:resource}> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} */
    private function createFixture(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P14A3_'.$key;
        $packageCode = 'P14A3'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P14a3 Synthetic', 'organization_code' => $key,
            'display_name' => 'P14a3 Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'manual',
            'source_system' => $sourceSystem, 'full_name' => 'P14a3 Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'P14a3 Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $timestamp = now();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attemptPublicId, 'participant_id' => $participant,
            'organization_id' => $organization, 'package_id' => $package, 'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case, 'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem');
    }

    private function auditCount(string $action): int
    {
        return DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
            ->where('subject_id', (string) $this->fixture['attempt'])->where('action', $action)->count();
    }
}
