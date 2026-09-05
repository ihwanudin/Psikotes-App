<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Registration\ConfirmIntegratedCheckout;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionJsonMutation;
use App\Http\Requests\ConfirmIntegratedCheckoutRequest;
use App\Models\ConsentRecord;
use App\Models\IntegrationClient;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use App\Services\Payments\AssessmentPriceSnapshot;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class IntegratedCheckoutConsentTest extends OrganizationPaymentTestCase
{
    private int $downstreamCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
            'http' => [
                'destination_origin' => 'https://psikotes.oncam.id',
                'trusted_exchange_origins' => [
                    'https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com',
                ],
                'exchange_per_minute' => 10, 'hydrate_per_minute' => 60, 'mutation_per_minute' => 10,
                'confirmation' => ['enabled' => true, 'max_body_bytes' => 4096],
            ],
        ]);
        config()->set('assessment_integration.checkout_session.http.confirmation.writer_enabled', true);
        config()->set('consent.documents.dass', [
            'version' => 'draft-2026-09-05',
            'title' => 'Persetujuan skrining DASS-21 sebagai bagian psikotes',
            'text' => 'DASS-21 wajib dalam rangkaian, diproses terpisah, dan bukan diagnosis.',
        ]);

        RateLimiter::for(CheckoutSessionHttpContract::LIMITER,
            fn (Request $request) => app(CheckoutSessionHttpContract::class)->rateLimit($request));
        Route::match(['GET', 'POST', 'PUT'], '/checkout/confirm', function (Request $request) {
            $this->downstreamCalls++;

            return response('', 204);
        })->middleware([
            ProtectCheckoutSessionHttpBoundary::class,
            AuthenticateCheckoutSession::class,
            VerifyCheckoutSessionJsonMutation::class,
            'throttle:'.CheckoutSessionHttpContract::LIMITER,
        ]);
        Route::match(['GET', 'PUT'], '/checkout/_test/confirm-method', fn () => response('', 204))
            ->middleware([AuthenticateCheckoutSession::class, VerifyCheckoutSessionJsonMutation::class]);
        Route::post('/checkout/_test/confirm-write', function (
            ConfirmIntegratedCheckoutRequest $request,
            ConfirmIntegratedCheckout $action,
        ) {
            $input = $request->toInput();
            if ($request->headers->get('X-Test-After-Auth') === 'revoke-source') {
                DB::table('integration_sources')->where('id', $input->principal->integrationSourceId)
                    ->update(['status' => 'REVOKED']);
            }
            if ($request->headers->get('X-Test-After-Auth') === 'change-payer') {
                DB::table('assessment_participants')->where('id', $input->principal->assessmentParticipantId)
                    ->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION']);
            }
            try {
                return response()->json($action->execute($input));
            } catch (DomainException) {
                return response()->json(['error' => 'CHECKOUT_CONFIRMATION_CONFLICT'], 409);
            }
        })->middleware([AuthenticateCheckoutSession::class, VerifyCheckoutSessionJsonMutation::class]);
    }

    public function test_exact_authenticated_json_reaches_the_downstream_boundary(): void
    {
        $fixture = $this->established();

        $this->mutation($fixture, $this->payload())->assertNoContent();
        $this->assertSame(1, $this->downstreamCalls);
    }

    public function test_boundary_is_default_off_and_validates_the_body_limit_config(): void
    {
        $fixture = $this->established();
        foreach ([null, false, true] as $enabled) {
            config()->set('assessment_integration.checkout_session.http.confirmation.enabled', $enabled);
            foreach ([null, 255, 8193, '4096'] as $bytes) {
                config()->set('assessment_integration.checkout_session.http.confirmation.max_body_bytes', $bytes);
                $this->mutation($fixture, $this->payload())->assertStatus(419)->assertSeeText('Page Expired');
            }
        }
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_security_metadata_and_single_csrf_channel_fail_closed_without_an_oracle(): void
    {
        $fixture = $this->established();
        $cases = [
            'origin missing' => ['server' => ['HTTP_ORIGIN' => null]],
            'origin foreign' => ['server' => ['HTTP_ORIGIN' => 'https://evil.test']],
            'csrf missing' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => null]],
            'csrf empty' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => '']],
            'csrf foreign' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => 'ocsrf1_'.str_repeat('0', 64)]],
            'csrf duplicate' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => $fixture['csrf'].','.$fixture['csrf']]],
            'fetch site' => ['server' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']],
            'fetch mode' => ['server' => ['HTTP_SEC_FETCH_MODE' => 'navigate']],
            'fetch dest' => ['server' => ['HTTP_SEC_FETCH_DEST' => 'document']],
        ];
        foreach ($cases as $label => $options) {
            $response = $this->mutation($fixture, $this->payload(), ...$options);
            $this->assertSame(419, $response->status(), $label);
            $response->assertSeeText('Page Expired');
        }
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_non_post_method_is_rejected_by_the_json_boundary(): void
    {
        $fixture = $this->established();
        $this->mutation($fixture, $this->payload(), method: 'PUT', path: '/checkout/_test/confirm-method')
            ->assertStatus(419)->assertSeeText('Page Expired');
    }

    public function test_test_only_route_uses_the_existing_mutation_limiter(): void
    {
        config()->set('assessment_integration.checkout_session.http.mutation_per_minute', 1);
        $fixture = $this->established();

        $this->mutation($fixture, $this->payload())->assertNoContent();
        $this->mutation($fixture, $this->payload())->assertTooManyRequests();
        $this->assertSame(1, $this->downstreamCalls);
    }

    public function test_non_json_empty_oversize_malformed_array_and_duplicate_keys_are_422(): void
    {
        $fixture = $this->established();
        $cases = [
            'empty' => [''],
            'whitespace' => ['  '],
            'form' => ['profile=forged', 'application/x-www-form-urlencoded'],
            'multipart' => ['--boundary', 'multipart/form-data; boundary=boundary'],
            'charset' => [$this->payload(), 'application/json; charset=UTF-8'],
            'malformed' => ['{"profile":'],
            'array' => ['[]'],
            'duplicate root' => ['{"profile":{},"profile":{}}'],
            'duplicate nested escaped' => ['{"profile":{"fullName":"A","full\\u004eame":"B"}}'],
            'oversize' => ['{"padding":"'.str_repeat('x', 4090).'"}'],
        ];
        foreach ($cases as $label => $case) {
            [$body, $contentType] = $case + [1 => 'application/json'];
            $this->mutation($fixture, $body, contentType: $contentType)
                ->assertStatus(422, $label)->assertSeeText('Unprocessable Content');
        }
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_authentication_runs_first_for_missing_foreign_and_revoked_sessions(): void
    {
        $fixture = $this->established();
        $this->mutation($fixture, $this->payload(), path: '/checkout/confirm?attempt=foreign')
            ->assertRedirect('/checkout/unavailable');
        $this->mutation($fixture, $this->payload(), cookies: [])->assertRedirect('/checkout/unavailable');
        $this->mutation($fixture, $this->payload(), cookies: [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => 'ocs1_'.str_repeat('0', 64),
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ])->assertRedirect('/checkout/unavailable');
        DB::table('checkout_sessions')->update([
            'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(), 'revocation_reason' => 'LOGOUT',
        ]);
        $this->mutation($fixture, $this->payload())->assertRedirect('/checkout/unavailable');
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_existing_logout_mutation_middleware_remains_byte_identical(): void
    {
        $this->assertSame(
            '830fe3010872aa46137c06f0a30057b7baf53040d6e5dc6cca96c6c07228653c',
            hash_file('sha256', app_path('Http/Middleware/VerifyCheckoutSessionMutation.php')),
        );
    }

    public function test_writer_completes_only_missing_required_profile_and_records_both_current_consents(): void
    {
        $fixture = $this->established();

        $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')
            ->assertOk()->assertExactJson([
                'replayed' => false,
                'profileFieldsCompleted' => 5,
                'consentsRecorded' => 2,
                'activatedTestTypes' => [],
            ]);
        $this->assertDatabaseHas('participants', [
            'full_name' => 'Synthetic Person', 'birth_date' => '2000-01-02 00:00:00', 'gender' => 'female',
            'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $this->assertSame(2, DB::table('consent_records')->where('participant_id', $fixture['participant'])
            ->where('status', 'accepted')->whereNull('withdrawn_at')->count());
    }

    public function test_writer_accepts_exact_missing_consent_subset_and_exact_replay_is_idempotent(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant'], ['psychotest']);
        $payload = $this->writerPayload(['dass']);

        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertExactJson([
                'replayed' => false,
                'profileFieldsCompleted' => 5,
                'consentsRecorded' => 1,
                'activatedTestTypes' => [],
            ]);
        $audit = DB::table('audit_logs')->where('action', 'checkout.confirmed')->sole();
        $context = json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['version', 'generation', 'sessionPublicId', 'requestHash'], array_keys($context));
        $this->assertSame(2, $context['version']);
        $this->assertSame(1, $context['generation']);
        $this->assertStringNotContainsString('dass', strtolower((string) $audit->context));
        $this->assertStringNotContainsString('psychotest', strtolower((string) $audit->context));
        $this->assertStringNotContainsString(ConsentDocument::for('dass')->version, (string) $audit->context);

        $before = [DB::table('consent_records')->count(), DB::table('audit_logs')->count()];
        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => true, 'consentsRecorded' => 0]);
        $this->assertSame($before, [DB::table('consent_records')->count(), DB::table('audit_logs')->count()]);
    }

    public function test_writer_accepts_profile_only_but_rejects_an_empty_completed_submission(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant'], ['psychotest', 'dass']);

        $this->mutation($fixture, $this->writerPayload([]), path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson([
                'replayed' => false,
                'profileFieldsCompleted' => 5,
                'consentsRecorded' => 0,
            ]);

        $complete = $this->established([
            'full_name' => 'Already Complete', 'birth_date' => '2000-01-02', 'gender' => 'female',
            'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO',
        ]);
        $this->acceptCurrentConsents($complete['participant'], ['psychotest', 'dass']);
        $this->mutation($complete, $this->writerPayload([], []), path: '/checkout/_test/confirm-write')
            ->assertConflict();
        $this->assertSame(0, DB::table('audit_logs')->where('subject_id', (string) $complete['attempt'])
            ->where('action', 'checkout.confirmed')->count());
    }

    public function test_writer_rejects_omitted_extra_and_noncanonical_current_consent_without_partial_writes(): void
    {
        $omitted = $this->established();
        $this->mutation($omitted, $this->writerPayload(['dass']), path: '/checkout/_test/confirm-write')
            ->assertConflict();

        $extra = $this->established();
        $this->acceptCurrentConsents($extra['participant'], ['psychotest']);
        $this->mutation($extra, $this->writerPayload(), path: '/checkout/_test/confirm-write')
            ->assertConflict();

        $noncanonical = $this->established();
        $this->acceptCurrentConsents($noncanonical['participant'], ['psychotest']);
        DB::table('consent_records')->where('participant_id', $noncanonical['participant'])
            ->where('consent_type', 'psychotest')->update(['document_hash' => str_repeat('0', 64)]);
        $this->mutation($noncanonical, $this->writerPayload(['dass']), path: '/checkout/_test/confirm-write')
            ->assertConflict();

        foreach ([$omitted, $extra, $noncanonical] as $fixture) {
            $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')
                ->where('subject_id', (string) $fixture['attempt'])->count());
        }
    }

    public function test_declined_and_withdrawn_current_consent_are_reaccepted_with_append_only_history(): void
    {
        foreach (['declined', 'withdrawn'] as $status) {
            $fixture = $this->established();
            $this->acceptCurrentConsents($fixture['participant'], ['psychotest']);
            $document = ConsentDocument::for('dass');
            $previousConsentedAt = $status === 'withdrawn' ? now()->subDays(2)->startOfSecond() : null;
            $previousWithdrawnAt = $status === 'withdrawn' ? now()->subDay()->startOfSecond() : null;
            $recordId = DB::table('consent_records')->insertGetId([
                'participant_id' => $fixture['participant'], 'consent_type' => 'dass', 'status' => $status,
                'document_version' => $document->version, 'document_hash' => $document->hash,
                'consented_at' => $previousConsentedAt, 'withdrawn_at' => $previousWithdrawnAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $payload = $this->writerPayload(['dass']);

            $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')
                ->assertOk()->assertJson(['replayed' => false, 'consentsRecorded' => 1]);
            $record = ConsentRecord::findOrFail($recordId);
            $this->assertSame('accepted', $record->status);
            $this->assertNotNull($record->consented_at);
            $this->assertTrue($record->consented_at->lessThanOrEqualTo(now()));
            $this->assertNull($record->withdrawn_at);

            $audit = DB::table('audit_logs')->where('action', 'checkout.consent_reaccepted')
                ->where('subject_type', ConsentRecord::class)->where('subject_id', (string) $recordId)->sole();
            $this->assertSame('checkout_session', $audit->actor_type);
            $this->assertSame([
                'version' => 1,
                'previousStatus' => $status,
                'previousConsentedAt' => $previousConsentedAt?->format('Y-m-d\TH:i:s\Z'),
                'previousWithdrawnAt' => $previousWithdrawnAt?->format('Y-m-d\TH:i:s\Z'),
            ], json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR));

            $before = DB::table('audit_logs')->count();
            $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')
                ->assertOk()->assertJson(['replayed' => true, 'consentsRecorded' => 0]);
            $this->assertSame($before, DB::table('audit_logs')->count());
        }
    }

    public function test_repeated_withdrawal_allows_the_same_payload_as_new_generations_but_older_hash_does_not_replay(): void
    {
        $fixture = $this->established();
        $first = $this->writerPayload();
        $this->mutation($fixture, $first, path: '/checkout/_test/confirm-write')->assertOk();
        DB::table('consent_records')->where('participant_id', $fixture['participant'])
            ->where('consent_type', 'dass')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $second = $this->writerPayload(['dass'], []);

        $this->mutation($fixture, $second, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => false, 'profileFieldsCompleted' => 0, 'consentsRecorded' => 1]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.consent_reaccepted')->count());

        DB::table('consent_records')->where('participant_id', $fixture['participant'])
            ->where('consent_type', 'dass')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->mutation($fixture, $second, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => false, 'profileFieldsCompleted' => 0, 'consentsRecorded' => 1]);
        $this->assertSame([1, 2, 3], DB::table('audit_logs')->where('action', 'checkout.confirmed')
            ->orderBy('id')->pluck('context')->map(static fn (string $context): int => json_decode($context, true, flags: JSON_THROW_ON_ERROR)['generation'])->all());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checkout.consent_reaccepted')->count());

        $before = DB::table('audit_logs')->count();
        $this->mutation($fixture, $second, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => true, 'consentsRecorded' => 0]);
        $this->mutation($fixture, $first, path: '/checkout/_test/confirm-write')->assertConflict();
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_document_rotation_after_confirmation_creates_a_new_consent_and_confirmation_generation(): void
    {
        $fixture = $this->established();
        $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')->assertOk();
        config()->set('consent.documents.dass', [
            'version' => 'draft-2026-09-06-rotated',
            'title' => 'Persetujuan skrining DASS-21 rotasi sintetis',
            'text' => 'Dokumen sintetis baru untuk membuktikan rotasi current consent.',
        ]);
        $rotated = $this->writerPayload(['dass'], []);

        $this->mutation($fixture, $rotated, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => false, 'profileFieldsCompleted' => 0, 'consentsRecorded' => 1]);
        $this->assertSame(2, DB::table('consent_records')->where('participant_id', $fixture['participant'])
            ->where('consent_type', 'dass')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());

        $before = DB::table('audit_logs')->count();
        $this->mutation($fixture, $rotated, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => true]);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_invalid_confirmation_history_and_actor_session_intervals_fail_closed(): void
    {
        $duplicate = $this->established();
        $payload = $this->writerPayload();
        $this->mutation($duplicate, $payload, path: '/checkout/_test/confirm-write')->assertOk();
        $audit = (array) DB::table('audit_logs')->where('action', 'checkout.confirmed')
            ->where('subject_id', (string) $duplicate['attempt'])->sole();
        unset($audit['id']);
        DB::table('audit_logs')->insert($audit);
        $this->mutation($duplicate, $payload, path: '/checkout/_test/confirm-write')->assertConflict();

        foreach (['gap', 'malformed', 'wrong-actor', 'wrong-actor-id', 'actor-mismatch',
            'foreign-actor', 'nonexistent-actor', 'invalid-time', 'future',
            'before-established', 'at-absolute-expiry', 'after-absolute-expiry'] as $case) {
            $corrupt = $this->established();
            $body = $this->writerPayload();
            $this->mutation($corrupt, $body, path: '/checkout/_test/confirm-write')->assertOk();
            $query = DB::table('audit_logs')->where('action', 'checkout.confirmed')
                ->where('subject_id', (string) $corrupt['attempt']);
            $row = $query->sole();
            if ($case === 'gap') {
                $context = json_decode((string) $row->context, true, flags: JSON_THROW_ON_ERROR);
                $context['generation'] = 2;
                $query->update(['context' => json_encode($context, JSON_THROW_ON_ERROR)]);
            } elseif ($case === 'malformed') {
                $query->update(['context' => '{"version":2}']);
            } elseif ($case === 'wrong-actor') {
                $query->update(['actor_type' => 'admin']);
            } elseif ($case === 'wrong-actor-id') {
                $query->update(['actor_id' => 'not-a-session-ulid']);
            } elseif ($case === 'actor-mismatch') {
                $context = json_decode((string) $row->context, true, flags: JSON_THROW_ON_ERROR);
                $context['sessionPublicId'] = (string) Str::ulid();
                $query->update(['context' => json_encode($context, JSON_THROW_ON_ERROR)]);
            } elseif (in_array($case, ['foreign-actor', 'nonexistent-actor'], true)) {
                $actorId = (string) Str::ulid();
                if ($case === 'foreign-actor') {
                    $foreign = $this->established();
                    $actorId = (string) DB::table('audit_logs')->where('branch_id', $foreign['organization'])
                        ->where('action', 'checkout_session.established')->value('actor_id');
                }
                $context = json_decode((string) $row->context, true, flags: JSON_THROW_ON_ERROR);
                $context['sessionPublicId'] = $actorId;
                $query->update(['actor_id' => $actorId, 'context' => json_encode($context, JSON_THROW_ON_ERROR)]);
            } elseif ($case === 'invalid-time') {
                $query->update(['occurred_at' => 'not-a-timestamp']);
            } elseif ($case === 'future') {
                $query->update(['occurred_at' => now()->addDay()]);
            } else {
                $establishedAt = now()->subHours(3)->startOfSecond();
                $idleExpiresAt = $establishedAt->copy()->addHour();
                $absoluteExpiresAt = $establishedAt->copy()->addHours(2);
                $establishedQuery = DB::table('audit_logs')->where('action', 'checkout_session.established')
                    ->where('subject_id', (string) $corrupt['attempt']);
                $establishedContext = json_decode(
                    (string) $establishedQuery->value('context'), true, flags: JSON_THROW_ON_ERROR);
                $establishedContext['establishedAt'] = $establishedAt->toISOString();
                $establishedContext['idleExpiresAt'] = $idleExpiresAt->toISOString();
                $establishedContext['absoluteExpiresAt'] = $absoluteExpiresAt->toISOString();
                $establishedQuery->update([
                    'occurred_at' => $establishedAt,
                    'context' => json_encode($establishedContext, JSON_THROW_ON_ERROR),
                ]);
                $confirmationAt = match ($case) {
                    'before-established' => $establishedAt->copy()->subSecond(),
                    'at-absolute-expiry' => $absoluteExpiresAt,
                    'after-absolute-expiry' => $absoluteExpiresAt->copy()->addSecond(),
                };
                $query->update(['occurred_at' => $confirmationAt]);
            }
            DB::table('consent_records')->where('participant_id', $corrupt['participant'])
                ->where('consent_type', 'dass')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            $before = DB::table('audit_logs')->count();
            $this->mutation($corrupt, $this->writerPayload(['dass'], []), path: '/checkout/_test/confirm-write')
                ->assertConflict();
            $this->assertSame($before, DB::table('audit_logs')->count());
        }
    }

    public function test_second_generation_failure_rolls_back_reaccept_and_new_history_only(): void
    {
        $fixture = $this->established();
        $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')->assertOk();
        $record = ConsentRecord::query()->where('participant_id', $fixture['participant'])
            ->where('consent_type', 'dass')->firstOrFail();
        $originalConsentedAt = $record->consented_at;
        $withdrawnAt = now()->startOfSecond();
        $record->forceFill(['status' => 'withdrawn', 'withdrawn_at' => $withdrawnAt])->save();
        DB::unprepared("CREATE TRIGGER p15_generation_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout.confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic generation rollback'); END");
        try {
            $this->mutation($fixture, $this->writerPayload(['dass'], []), path: '/checkout/_test/confirm-write')
                ->assertServerError();
        } finally {
            DB::unprepared('DROP TRIGGER p15_generation_audit_failure');
        }

        $record->refresh();
        $this->assertSame('withdrawn', $record->status);
        $this->assertTrue($record->consented_at->equalTo($originalConsentedAt));
        $this->assertTrue($record->withdrawn_at->equalTo($withdrawnAt));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.consent_reaccepted')->count());
    }

    public function test_future_accepted_consent_conflicts_before_profile_or_audit_write(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant'], ['psychotest', 'dass']);
        DB::table('consent_records')->where('participant_id', $fixture['participant'])
            ->where('consent_type', 'dass')->update(['consented_at' => now()->addDay()]);

        $this->mutation($fixture, $this->writerPayload([]), path: '/checkout/_test/confirm-write')
            ->assertConflict();
        $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', [
            'checkout.confirmed', 'checkout.consent_reaccepted',
        ])->count());

        $replay = $this->established();
        $payload = $this->writerPayload();
        $this->mutation($replay, $payload, path: '/checkout/_test/confirm-write')->assertOk();
        DB::table('consent_records')->where('participant_id', $replay['participant'])
            ->where('consent_type', 'dass')->update(['consented_at' => now()->addDay()]);
        $before = DB::table('audit_logs')->count();
        $this->mutation($replay, $payload, path: '/checkout/_test/confirm-write')->assertConflict();
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_reaccept_history_and_record_transition_roll_back_with_confirmation_audit_failure(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant'], ['psychotest']);
        $document = ConsentDocument::for('dass');
        $recordId = DB::table('consent_records')->insertGetId([
            'participant_id' => $fixture['participant'], 'consent_type' => 'dass', 'status' => 'declined',
            'document_version' => $document->version, 'document_hash' => $document->hash,
            'consented_at' => null, 'withdrawn_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::unprepared("CREATE TRIGGER p15_reaccept_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout.confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic reaccept rollback'); END");
        try {
            $this->mutation($fixture, $this->writerPayload(['dass']), path: '/checkout/_test/confirm-write')
                ->assertServerError();
        } finally {
            DB::unprepared('DROP TRIGGER p15_reaccept_audit_failure');
        }

        $this->assertDatabaseHas('consent_records', [
            'id' => $recordId, 'status' => 'declined', 'consented_at' => null, 'withdrawn_at' => null,
        ]);
        $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', [
            'checkout.confirmed', 'checkout.consent_reaccepted',
        ])->count());
    }

    public function test_writer_exact_replay_is_idempotent_but_changed_or_prelocked_profile_conflicts(): void
    {
        $fixture = $this->established();
        $payload = $this->writerPayload();
        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')->assertOk();
        $before = [DB::table('consent_records')->count(), DB::table('audit_logs')->count(),
            DB::table('outbox_messages')->count(), DB::table('assessment_bills')->count()];

        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => true, 'profileFieldsCompleted' => 0,
                'consentsRecorded' => 0, 'activatedTestTypes' => []]);
        $this->assertSame($before, [DB::table('consent_records')->count(), DB::table('audit_logs')->count(),
            DB::table('outbox_messages')->count(), DB::table('assessment_bills')->count()]);

        DB::table('consent_records')->where('participant_id', $fixture['participant'])
            ->where('consent_type', 'dass')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')->assertConflict();

        $changed = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $changed['profile']['fullName'] = 'Another Person';
        $this->mutation($fixture, json_encode($changed, JSON_THROW_ON_ERROR), path: '/checkout/_test/confirm-write')
            ->assertConflict();
        $missing = json_decode($this->writerPayload(), true, flags: JSON_THROW_ON_ERROR);
        unset($missing['profile']['intendedField']);
        $fresh = $this->established();
        $this->mutation($fresh, json_encode($missing, JSON_THROW_ON_ERROR), path: '/checkout/_test/confirm-write')
            ->assertConflict();
        $prelocked = $this->established(['full_name' => 'Already Locked']);
        $this->mutation($prelocked, $payload, path: '/checkout/_test/confirm-write')->assertConflict();
        $this->assertDatabaseHas('participants', ['id' => $prelocked['participant'], 'full_name' => 'Already Locked']);
    }

    public function test_replay_rejects_a_required_locked_profile_field_that_became_missing(): void
    {
        $fixture = $this->established(['full_name' => 'Initially Locked']);
        $payload = $this->writerPayload(profile: [
            'birthDate' => '2000-01-02', 'gender' => 'FEMALE',
            'educationLevel' => 'SMA_SMK', 'intendedField' => 'KAIGO',
        ]);
        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')->assertOk();
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => null]);
        $before = [
            DB::table('audit_logs')->count(), DB::table('consent_records')->count(),
            DB::table('assessment_entitlements')->count(), DB::table('outbox_messages')->count(),
        ];

        $this->mutation($fixture, $payload, path: '/checkout/_test/confirm-write')->assertConflict();
        $this->assertSame($before, [
            DB::table('audit_logs')->count(), DB::table('consent_records')->count(),
            DB::table('assessment_entitlements')->count(), DB::table('outbox_messages')->count(),
        ]);
        $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
    }

    public function test_request_rejects_authority_optional_identity_and_non_explicit_consent_fields(): void
    {
        $fixture = $this->established();
        $valid = json_decode($this->writerPayload(), true, flags: JSON_THROW_ON_ERROR);
        $cases = [
            ['branchId' => $fixture['organization']], ['sourceSystem' => 'FORGED'], ['clientId' => 1],
            ['participantId' => $fixture['participant']], ['assessmentAttemptId' => 'FORGED'],
            ['packageId' => $fixture['package']], ['payerType' => 'self'], ['amount' => 0],
            ['paymentStatus' => 'paid'], ['identityVerified' => true],
        ];
        foreach ($cases as $extra) {
            $this->mutation($fixture, json_encode([...$valid, ...$extra], JSON_THROW_ON_ERROR),
                path: '/checkout/_test/confirm-write')->assertUnprocessable();
        }
        foreach ([false, 1, 'yes', 'on'] as $notStrictTrue) {
            $payload = $valid;
            $payload['consents']['dass']['accepted'] = $notStrictTrue;
            $this->mutation($fixture, json_encode($payload, JSON_THROW_ON_ERROR), path: '/checkout/_test/confirm-write')
                ->assertUnprocessable();
        }
        $payload = $valid;
        $payload['profile']['email'] = 'forged@example.test';
        $this->mutation($fixture, json_encode($payload, JSON_THROW_ON_ERROR), path: '/checkout/_test/confirm-write')
            ->assertUnprocessable();
        unset($valid['consents']['psychotest']);
        $this->mutation($fixture, json_encode($valid, JSON_THROW_ON_ERROR), path: '/checkout/_test/confirm-write')
            ->assertConflict();
        $this->assertDatabaseCount('consent_records', 0);
        $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
    }

    public function test_wrong_current_document_version_or_hash_rolls_back_all_writes(): void
    {
        foreach (['version', 'hash'] as $case) {
            $fixture = $this->established();
            $payload = json_decode($this->writerPayload(), true, flags: JSON_THROW_ON_ERROR);
            $payload['consents'][$case === 'version' ? 'dass' : 'psychotest'][
                $case === 'version' ? 'documentVersion' : 'documentHash'
            ] = $case === 'version' ? 'obsolete' : str_repeat('0', 64);
            $this->mutation($fixture, json_encode($payload, JSON_THROW_ON_ERROR), path: '/checkout/_test/confirm-write')
                ->assertConflict();
            $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
            $this->assertSame(0, DB::table('consent_records')->where('participant_id', $fixture['participant'])->count());
        }
    }

    public function test_settled_attempt_activates_both_tests_without_creating_another_bill(): void
    {
        $fixture = $this->established(settled: true, identity: true);
        $beforeBills = DB::table('assessment_bills')->count();
        $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['activatedTestTypes' => ['dass21', 'ist']]);
        $this->assertSame($beforeBills, DB::table('assessment_bills')->count());
        $this->assertDatabaseHas('assessment_participants', ['id' => $fixture['attempt'], 'assessment_status' => 'READY']);
        $this->assertSame(['dass21', 'ist'], DB::table('assessment_entitlements')
            ->where('assessment_participant_id', $fixture['attempt'])->orderBy('test_type')->pluck('test_type')->all());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
        $before = [DB::table('audit_logs')->count(), DB::table('outbox_messages')->count(),
            DB::table('assessment_entitlements')->count(), DB::table('assessment_bills')->count()];
        $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')
            ->assertOk()->assertJson(['replayed' => true, 'activatedTestTypes' => []]);
        $this->assertSame($before, [DB::table('audit_logs')->count(), DB::table('outbox_messages')->count(),
            DB::table('assessment_entitlements')->count(), DB::table('assessment_bills')->count()]);
    }

    public function test_settled_without_identity_and_unpaid_both_remain_locked(): void
    {
        foreach ([['settled' => true, 'identity' => false], ['settled' => false, 'identity' => true]] as $case) {
            $fixture = $this->established(settled: $case['settled'], identity: $case['identity']);
            $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')
                ->assertOk()->assertJson(['activatedTestTypes' => []]);
            $this->assertDatabaseHas('assessment_participants', [
                'id' => $fixture['attempt'], 'assessment_status' => 'PROVISIONED',
            ]);
            $this->assertSame(0, DB::table('assessment_entitlements')
                ->where('assessment_participant_id', $fixture['attempt'])->where('status', 'ready')->count());
        }
    }

    public function test_writer_is_default_off_and_audit_failure_rolls_back_profile_and_consents(): void
    {
        $fixture = $this->established();
        config()->set('assessment_integration.checkout_session.http.confirmation.writer_enabled', false);
        $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')->assertServerError();
        config()->set('assessment_integration.checkout_session.http.confirmation.writer_enabled', true);
        DB::unprepared("CREATE TRIGGER p15_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout.confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic p15 audit failure'); END");
        try {
            $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write')->assertServerError();
        } finally {
            DB::unprepared('DROP TRIGGER p15_audit_failure');
        }
        $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
        $this->assertSame(0, DB::table('consent_records')->where('participant_id', $fixture['participant'])->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
    }

    public function test_writer_reloads_scope_after_authentication_before_any_write(): void
    {
        foreach (['revoke-source', 'change-payer'] as $mutation) {
            $fixture = $this->established();
            $this->mutation($fixture, $this->writerPayload(), path: '/checkout/_test/confirm-write', server: [
                'HTTP_X_TEST_AFTER_AUTH' => $mutation,
            ])->assertConflict();
            $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
        }
        $this->assertDatabaseCount('consent_records', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,selector:string,csrf:string} */
    private function established(array $participantOverrides = [], bool $settled = false, bool $identity = false): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P15_'.$key;
        $packageCode = 'P15'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId(array_replace([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => null, 'phone' => '620000000000',
        ], $participantOverrides));
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => json_encode([$packageCode]),
            'allowed_funding_modes' => json_encode(['COMMERCIAL_SELF_PAY']), 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('participants')->where('id', $participant)->update(['package_id' => $package]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']),
        ]);
        if ($settled) {
            $method = DB::table('payment_methods')->insertGetId([
                'code' => strtolower($key), 'display_name' => 'Synthetic', 'is_active' => true,
            ]);
            $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($package), false);
            $charge = DB::table('assessment_charges')->insertGetId([
                'assessment_participant_id' => $attempt, 'organization_id' => $organization,
                'participant_id' => $participant, 'package_id' => $package, 'payer_type' => 'self',
                'base_amount' => 100, 'consultation_amount' => 0, 'amount' => 100, 'currency' => 'IDR',
                'price_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'policy_snapshot' => '{}',
            ]);
            $bill = DB::table('assessment_bills')->insertGetId([
                'organization_id' => $organization, 'payer_type' => 'self', 'payer_participant_id' => $participant,
                'public_reference' => 'AB_'.$key, 'status' => 'paid', 'amount' => 100, 'currency' => 'IDR',
                'item_count' => 1, 'selection_hash' => hash('sha256', 'selection'.$key),
                'idempotency_key' => $key, 'request_hash' => hash('sha256', 'bill'.$key),
                'payment_method_id' => $method, 'paid_at' => now(),
            ]);
            DB::table('assessment_bill_items')->insert([
                'bill_id' => $bill, 'charge_id' => $charge, 'organization_id' => $organization,
                'participant_id' => $participant, 'payer_type' => 'self', 'payer_participant_id' => $participant,
                'amount' => 100, 'currency' => 'IDR', 'settled_at' => now(),
            ]);
        }
        if ($identity) {
            foreach (['identity_document', 'initial_selfie'] as $type) {
                $publicId = (string) Str::ulid();
                DB::table('identity_evidence')->insert([
                    'public_id' => $publicId, 'participant_id' => $participant, 'type' => $type,
                    'disk' => 'local', 'object_key' => 'synthetic/'.$publicId, 'mime_type' => 'image/jpeg',
                    'size_bytes' => 100, 'width' => 10, 'height' => 10,
                    'checksum_sha256' => hash('sha256', $publicId), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('identity_verifications')->insert([
                'participant_id' => $participant, 'matcher' => 'synthetic', 'outcome' => 'match',
                'manual_status' => 'pending', 'checked_at' => now(),
            ]);
        }
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($client), $attemptPublicId,
                $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $established = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($issued->rawToken()));

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt')
            + ['selector' => $established->rawSelector(), 'csrf' => $established->rawCsrfToken()];
    }

    private function payload(): string
    {
        return json_encode([
            'profile' => ['fullName' => 'Synthetic Person'],
            'consents' => [
                'psychotest' => ['accepted' => true, 'documentVersion' => 'draft-2026-08-25.2'],
                'dass' => ['accepted' => true, 'documentVersion' => 'draft-2026-09-05'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $consentTypes
     * @param  array<string, string>|null  $profile
     */
    private function writerPayload(array $consentTypes = ['psychotest', 'dass'], ?array $profile = null): string
    {
        $psychotest = ConsentDocument::for('psychotest');
        $dass = ConsentDocument::for('dass');

        $consents = [
            'psychotest' => ['accepted' => true, 'documentVersion' => $psychotest->version,
                'documentHash' => $psychotest->hash],
            'dass' => ['accepted' => true, 'documentVersion' => $dass->version,
                'documentHash' => $dass->hash],
        ];

        return json_encode([
            'profile' => $profile ?? [
                'fullName' => 'Synthetic Person', 'birthDate' => '2000-01-02', 'gender' => 'FEMALE',
                'educationLevel' => 'SMA_SMK', 'intendedField' => 'KAIGO',
            ],
            'consents' => array_intersect_key($consents, array_flip($consentTypes)),
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $types */
    private function acceptCurrentConsents(int $participantId, array $types): void
    {
        foreach ($types as $type) {
            $document = ConsentDocument::for($type);
            DB::table('consent_records')->insert([
                'participant_id' => $participantId, 'consent_type' => $type, 'status' => 'accepted',
                'document_version' => $document->version, 'document_hash' => $document->hash,
                'consented_at' => now(), 'withdrawn_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string, string>|null $cookies
     * @param  array<string, string|null>  $server
     */
    private function mutation(array $fixture, string $body, string $method = 'POST', string $path = '/checkout/confirm',
        string $contentType = 'application/json', ?array $cookies = null, array $server = [])
    {
        $cookies ??= [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $fixture['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ];
        $defaults = [
            'HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'https://psikotes.oncam.id', 'HTTP_X_CHECKOUT_CSRF' => $fixture['csrf'],
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
        ];

        return $this->call($method, $path, [], $cookies, [], array_filter(
            array_replace($defaults, $server), static fn ($value): bool => $value !== null,
        ), $body);
    }
}
