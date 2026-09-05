<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Integrations\ProvisionCheckoutParticipant;
use App\Actions\Payments\ActivateSettledAssessment;
use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\FinalizeAssessmentBill;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\AdminRole;
use App\Enums\CheckoutHandoffIntent;
use App\Enums\PayerType;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\OutboxMessage;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\OrganizationPaymentTestCase;

/** SQLite acceptance composition; concurrency and PostgreSQL RLS remain separate evidence. */
final class CheckoutAcceptanceMatrixTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session.enabled', true);
        config()->set('assessment_integration.checkout_session.http.confirmation.enabled', true);
        config()->set('assessment_integration.checkout_session.http.confirmation.writer_enabled', true);
        config()->set('consent.legal_review_pending', false);
        config()->set('consent.documents.dass', [
            'version' => 'acceptance-2026-09-05',
            'title' => 'Persetujuan DASS-21 sintetis',
            'text' => 'DASS-21 adalah bagian psikotes dan bukan diagnosis.',
        ]);
        foreach (['exchange', 'hydrate', 'mutation'] as $operation) {
            RateLimiter::clear('checkout-http:'.$operation.':127.0.0.41');
        }
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_canonical_checkout_keeps_collective_self_attempt_and_private_projection_scoped(): void
    {
        $organization = Branch::create([
            'code' => 'ACCEPT', 'ref_code' => 'ACCEPT', 'name' => 'Acceptance Branch',
            'organization_code' => 'ACCEPT', 'display_name' => 'Acceptance Branch',
            'status' => 'ACTIVE', 'is_active' => true, 'allowed_payer_types' => ['self', 'organization'],
        ]);
        $packages = $this->packages();
        $sources = [
            $this->source($organization, 'SOURCE_ALPHA', array_keys($packages)),
            $this->source($organization, 'SOURCE_BETA', array_keys($packages)),
        ];

        $attempts = [];
        foreach (range(0, 9) as $index) {
            $source = $sources[$index < 5 ? 0 : 1];
            $packageCode = array_keys($packages)[$index % count($packages)];
            $profile = $index === 0 ? [] : [
                'fullName' => 'Acceptance Participant '.($index + 1),
                'birthDate' => '2000-01-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'gender' => $index % 2 === 0 ? 'MALE' : 'FEMALE',
                'educationLevel' => 'SMA_SMK', 'intendedField' => 'UMUM',
                'phone' => '620000000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ];
            $attempts[] = $this->provision(
                $source, $packageCode, 'CANDIDATE-'.$index, 'ROUND-PRIMARY', 'organization', $profile,
            );
        }
        $this->assertCount(10, array_unique(array_column($attempts, 'participant')));
        $this->assertSame(5, collect($attempts)->where('source', 'SOURCE_ALPHA')->count());
        $this->assertSame(5, collect($attempts)->where('source', 'SOURCE_BETA')->count());
        $this->assertNull(Participant::findOrFail($attempts[0]['participant'])->full_name);
        $this->assertSame('Acceptance Participant 2', Participant::findOrFail($attempts[1]['participant'])->full_name);

        $self = $this->provision(
            $sources[0], $attempts[0]['packageCode'], 'CANDIDATE-0', 'ROUND-SELF', 'self', [],
        );
        $this->assertSame($attempts[0]['participant'], $self['participant']);
        $this->assertNotSame($attempts[0]['attempt'], $self['attempt']);
        $this->assertSame(2, AssessmentParticipant::query()
            ->where('participant_id', $self['participant'])->count());

        foreach (array_column($attempts, 'participant') as $participant) {
            $this->identityPrerequisites($participant);
        }
        $selection = array_map(static fn (array $attempt): array => [
            'assessmentParticipantId' => $attempt['attempt'],
            'consultationRequested' => $attempt['packageCode'] === 'ACCEPT_FREE',
        ], $attempts);
        $admin = Admin::create([
            'branch_id' => $organization->id, 'name' => 'Acceptance Admin',
            'email' => 'acceptance-admin@example.test', 'password' => 'synthetic-only',
            'role' => AdminRole::BranchAdmin,
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic Xendit', 'is_active' => true,
        ]);

        $collective = app(RlsContextRunner::class)->runAsService(function () use ($organization, $selection, $admin, $method): AssessmentBill {
            $preview = app(PreviewAssessmentBill::class)->execute($organization->id, $selection, PayerType::Organization);
            $this->assertTrue($preview['canReserve']);
            $this->assertSame(10, $preview['paidCount']);
            $this->assertSame(0, $preview['freeCount']);
            $this->assertSame(0, $preview['items'][2]['snapshot']['baseAmount']);
            $this->assertSame(30, $preview['items'][2]['snapshot']['consultationAmount']);
            $bill = app(ReserveAssessmentBill::class)->execute(
                $admin, $selection, $method, $preview['selectionHash'], 'acceptance-collective',
            );
            app(ClaimAssessmentBillInvoice::class)->execute($organization->id, $bill->id);

            return $bill;
        });
        $intent = OutboxMessage::query()->where('topic', 'assessment.bill.invoice-issuance')
            ->where('aggregate_id', (string) $collective->id)->sole();
        app(IssueAssessmentBillInvoice::class)->execute($intent->message_id);
        $collective->refresh();
        $provider = app(PaymentProvider::class);
        $this->assertInstanceOf(FakePaymentProvider::class, $provider);
        $this->assertNotNull($collective->gateway_ref);
        $settled = app(FinalizeAssessmentBill::class)->execute(
            $provider->markPaid($collective->gateway_ref, 'acceptance-collective-paid'),
        );
        $this->assertSame(['decision' => 'settled', 'allocationCount' => 10, 'activatedAttemptCount' => 0], $settled);
        $this->assertDatabaseHas('assessment_bills', ['id' => $collective->id, 'payer_type' => 'organization',
            'payer_participant_id' => null, 'status' => 'paid', 'item_count' => 10]);

        $participant = Participant::findOrFail($self['participant']);
        $selfSelection = [['assessmentParticipantId' => $self['attempt'], 'consultationRequested' => false]];
        $selfBill = app(RlsContextRunner::class)->runAsService(function () use ($organization, $participant, $selfSelection, $method): AssessmentBill {
            $preview = app(PreviewAssessmentBill::class)->execute(
                $organization->id, $selfSelection, PayerType::SelfPay, $participant->id,
            );

            return app(ReserveAssessmentBill::class)->execute(
                $participant, $selfSelection, $method, $preview['selectionHash'], 'acceptance-self',
            );
        });
        $this->assertDatabaseHas('assessment_bills', ['id' => $selfBill->id, 'payer_type' => 'self',
            'payer_participant_id' => $participant->id, 'status' => 'reserved', 'item_count' => 1]);
        $this->assertDatabaseCount('assessment_bills', 2);
        $this->assertDatabaseCount('assessment_bill_items', 11);

        foreach ([...array_column($attempts, 'attempt'), $self['attempt']] as $attempt) {
            $this->assertLocked($attempt, (int) AssessmentParticipant::findOrFail($attempt)->participant_id, $organization->id);
        }

        $foreign = Branch::create([
            'code' => 'FOREIGN', 'ref_code' => 'FOREIGN', 'name' => 'Foreign Branch',
            'organization_code' => 'FOREIGN', 'display_name' => 'Foreign Branch',
            'status' => 'ACTIVE', 'is_active' => true, 'allowed_payer_types' => ['organization'],
        ]);
        $foreignSource = $this->source($foreign, 'SOURCE_FOREIGN', array_keys($packages));
        $foreignPreview = app(RlsContextRunner::class)->runAsService(fn (): array => app(PreviewAssessmentBill::class)
            ->execute($foreign->id, [$selection[0]], PayerType::Organization));
        $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $foreignPreview['items'][0]['reason']);
        $this->assertNull($foreignPreview['totalAmount']);
        try {
            $this->issue($foreignSource['client'], $attempts[0], 'foreign-denial');
            $this->fail('Foreign integration client issued a handoff for the owner attempt.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->getMessage());
        }

        $alphaCredentials = $this->credentials($this->exchange($this->issue(
            $sources[0]['client'], $attempts[0], 'alpha-paid',
        ))->assertStatus(303));
        $betaCredentials = $this->credentials($this->exchange($this->issue(
            $sources[1]['client'], $attempts[5], 'beta-paid',
        ), 'https://seleksi.serbaindo.com')->assertStatus(303));
        $selfCredentials = $this->credentials($this->exchange($this->issue(
            $sources[0]['client'], $self, 'alpha-self',
        ))->assertStatus(303));

        $alphaSummary = $this->summary($alphaCredentials)->assertOk();
        $betaSummary = $this->summary($betaCredentials)->assertOk();
        $selfSummary = $this->summary($selfCredentials)->assertOk();
        $this->assertSame('paid', $alphaSummary->viewData('summary')['payment']['state']);
        $this->assertSame('paid', $betaSummary->viewData('summary')['payment']['state']);
        $this->assertSame('preparing', $selfSummary->viewData('summary')['payment']['state']);
        $this->assertIsArray($alphaSummary->viewData('confirmationForm'));

        $this->clinicalSentinel($attempts[1]['participant']);
        $privateValues = [...array_column($attempts, 'externalCandidate'),
            $collective->public_reference, (string) $collective->invoice_url, (string) $collective->gateway_ref,
            'PRIVATE-DASS-CLINICAL-MARKER', 'ROUND-PRIMARY', 'SOURCE_ALPHA', 'SOURCE_BETA',
            'Acceptance Participant 2',
        ];
        foreach ([$alphaSummary, $betaSummary, $selfSummary] as $response) {
            $this->assertPrivateProjection($response, $privateValues);
        }
        $this->summary($betaCredentials, '?attempt='.$attempts[0]['attempt'])->assertStatus(303)
            ->assertRedirect('/checkout/unavailable');

        $confirmed = $this->confirm($alphaCredentials)->assertOk()->assertExactJson([
            'data' => ['confirmed' => true, 'replayed' => false],
        ]);
        $this->assertPrivateHeaders($confirmed);
        $this->assertDatabaseHas('participants', [
            'id' => $attempts[0]['participant'], 'full_name' => 'Acceptance Completed Profile',
        ]);
        $activationReplay = app(RlsContextRunner::class)->runAsService(
            fn (): array => app(ActivateSettledAssessment::class)->execute(
                new AssessmentPrincipal($attempts[0]['participant'], $organization->id, $attempts[0]['attempt']),
            ),
        );
        $this->assertSame([], $activationReplay, json_encode([
            'consents' => DB::table('consent_records')->where('participant_id', $attempts[0]['participant'])->count(),
            'evidence' => DB::table('identity_evidence')->where('participant_id', $attempts[0]['participant'])->count(),
            'verification' => DB::table('identity_verifications')->where('participant_id', $attempts[0]['participant'])->first(),
            'charge' => DB::table('assessment_charges')->where('assessment_participant_id', $attempts[0]['attempt'])->first(),
        ], JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('assessment_participants', [
            'id' => $attempts[0]['attempt'], 'assessment_status' => 'READY',
        ]);
        $this->assertSame(2, DB::table('assessment_entitlements')
            ->where('assessment_participant_id', $attempts[0]['attempt'])->count());
        $this->assertDatabaseHas('assessment_participants', [
            'id' => $self['attempt'], 'assessment_status' => 'PROVISIONED',
        ]);
        $this->assertDatabaseMissing('assessment_entitlements', ['assessment_participant_id' => $self['attempt']]);
        $this->assertLocked($self['attempt'], $self['participant'], $organization->id);
        foreach (array_slice($attempts, 1) as $other) {
            $this->assertDatabaseHas('assessment_participants', [
                'id' => $other['attempt'], 'assessment_status' => 'PROVISIONED',
            ]);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.confirmed')
            ->where('subject_id', (string) $attempts[0]['attempt'])->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')
            ->where('subject_id', (string) $self['attempt'])->count());
    }

    /** @return array<string,TestPackage> */
    private function packages(): array
    {
        $packages = [];
        foreach ([['ACCEPT_STANDARD', 100], ['ACCEPT_PLUS', 250], ['ACCEPT_FREE', 0]] as [$code, $amount]) {
            $package = TestPackage::create([
                'code' => $code, 'name' => 'Synthetic '.$code, 'amount' => $amount,
                'consultation_amount' => 30, 'currency' => 'IDR', 'is_active' => true,
            ]);
            $package->items()->createMany([
                ['test_type' => 'ist', 'sort_order' => 1],
                ['test_type' => 'dass21', 'sort_order' => 2],
            ]);
            $packages[$code] = $package;
        }

        return $packages;
    }

    /** @param list<string> $packageCodes
     * @return array{client:IntegrationClient,source:string}
     */
    private function source(Branch $organization, string $system, array $packageCodes): array
    {
        $client = IntegrationClient::create([
            'organization_id' => $organization->id, 'client_id' => strtolower($system),
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ])->refresh();
        IntegrationSource::create([
            'integration_client_id' => $client->id, 'source_system' => $system,
            'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => $packageCodes,
            'allowed_funding_modes' => [], 'allowed_payer_types' => ['self', 'organization'],
        ]);

        return ['client' => $client, 'source' => $system];
    }

    /** @param array{client:IntegrationClient,source:string} $source
     * @param  array<string,mixed>  $profile
     * @return array{attempt:int,attemptReference:string,participant:int,packageCode:string,source:string,externalCandidate:string}
     */
    private function provision(array $source, string $packageCode, string $candidate, string $round,
        string $payer, array $profile): array
    {
        $input = [
            'contractVersion' => 'checkout-v2', 'sourceSystem' => $source['source'],
            'organizationCode' => $source['client']->organization->organization_code,
            'externalCandidateId' => $candidate, 'assessmentRoundId' => $round,
            'assessmentPackageCode' => $packageCode, 'payerType' => $payer, 'profile' => $profile,
        ];
        $request = ProvisionCheckoutParticipantRequest::create('/_internal', 'POST', $input);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->attributes->set('integration_client', $source['client']);
        $request->headers->set('Idempotency-Key', 'acceptance-'.strtolower($source['source']).'-'.strtolower($candidate).'-'.strtolower($round));
        $request->validateResolved();
        $result = app(RlsContextRunner::class)->runAsService(
            fn (): array => app(ProvisionCheckoutParticipant::class)->handle($request),
        );
        $attempt = AssessmentParticipant::query()->where('assessment_attempt_id', $result['assessment_attempt_id'])->sole();

        return [
            'attempt' => $attempt->id, 'attemptReference' => $attempt->assessment_attempt_id,
            'participant' => $attempt->participant_id, 'packageCode' => $packageCode,
            'source' => $source['source'], 'externalCandidate' => $candidate,
        ];
    }

    /** @param array{attemptReference:string,source:string} $attempt */
    private function issue(IntegrationClient $client, array $attempt, string $key): string
    {
        $issued = app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)->execute(
            new CheckoutHandoffIssueInput(
                $client, $attempt['attemptReference'], $attempt['source'],
                'ih1_'.substr(hash('sha256', $key), 0, 32), CheckoutHandoffIntent::Issue,
            ),
        ));

        return $issued->rawToken();
    }

    private function identityPrerequisites(int $participant): void
    {
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

    private function clinicalSentinel(int $participant): void
    {
        $document = ConsentDocument::for('dass');
        $consent = DB::table('consent_records')->insertGetId([
            'participant_id' => $participant, 'consent_type' => 'dass', 'status' => 'accepted',
            'document_version' => $document->version, 'document_hash' => $document->hash,
            'consented_at' => now(),
        ]);
        $assessment = DB::table('dass_assessments')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'consent_record_id' => $consent, 'status' => 'completed', 'started_at' => now()->subMinute(),
            'completed_at' => now(), 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dass_results')->insert([
            'assessment_id' => $assessment, 'depression_raw' => 1, 'anxiety_raw' => 2, 'stress_raw' => 3,
            'depression_score' => 2, 'anxiety_score' => 4, 'stress_score' => 6,
            'depression_category' => 'PRIVATE-DASS-CLINICAL-MARKER', 'anxiety_category' => 'synthetic',
            'stress_category' => 'synthetic', 'overall_category' => 'synthetic', 'follow_up' => 'synthetic',
            'validity_flags' => '[]', 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertLocked(int $attempt, int $participant, int $organization): void
    {
        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentEntitlementGate::class)
                ->assertReady(new AssessmentPrincipal($participant, $organization, $attempt), 'ist'));
            $this->fail('Attempt unexpectedly inherited access.');
        } catch (EntitlementLocked) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return TestResponse<Response> */
    private function exchange(string $raw, string $origin = 'https://seleksi.beasiswajepang.id'): TestResponse
    {
        return $this->call('POST', '/checkout/session', ['handoffToken' => $raw], [], [],
            $this->server($origin), 'handoffToken='.$raw);
    }

    /** @param TestResponse<Response> $response
     * @return array{selector:string,csrf:string}
     */
    private function credentials(TestResponse $response): array
    {
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie): string => $cookie->getName());

        return [
            'selector' => (string) $cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE)?->getValue(),
            'csrf' => (string) $cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE)?->getValue(),
        ];
    }

    /** @param array{selector:string,csrf:string} $credentials
     * @return TestResponse<Response>
     */
    private function summary(array $credentials, string $query = ''): TestResponse
    {
        return $this->call('GET', '/checkout'.$query, [], $this->cookieMap($credentials), [], $this->server(null));
    }

    /** @param array{selector:string,csrf:string} $credentials
     * @return TestResponse<Response>
     */
    private function confirm(array $credentials): TestResponse
    {
        $psychotest = ConsentDocument::for('psychotest');
        $dass = ConsentDocument::for('dass');
        $body = json_encode([
            'profile' => [
                'fullName' => 'Acceptance Completed Profile', 'birthDate' => '2000-01-01',
                'gender' => 'MALE', 'educationLevel' => 'SMA_SMK', 'intendedField' => 'UMUM',
                'phone' => '620000000999',
            ],
            'consents' => [
                'psychotest' => ['accepted' => true, 'documentVersion' => $psychotest->version,
                    'documentHash' => $psychotest->hash],
                'dass' => ['accepted' => true, 'documentVersion' => $dass->version,
                    'documentHash' => $dass->hash],
            ],
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/checkout/confirm', [], $this->cookieMap($credentials), [], [
            ...$this->server('https://psikotes.oncam.id'), 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CHECKOUT_CSRF' => $credentials['csrf'],
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
        ], $body);
    }

    /** @param array{selector:string,csrf:string} $credentials
     * @return array<string,string>
     */
    private function cookieMap(array $credentials): array
    {
        return [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $credentials['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $credentials['csrf'],
        ];
    }

    /** @return array<string,string> */
    private function server(?string $origin): array
    {
        $server = [
            'HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'REMOTE_ADDR' => '127.0.0.41',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return $server;
    }

    /** @param TestResponse<Response> $response
     * @param  list<string>  $privateValues
     */
    private function assertPrivateProjection(TestResponse $response, array $privateValues): void
    {
        $this->assertPrivateHeaders($response);
        $summary = $response->viewData('summary');
        $this->assertIsArray($summary);
        $keys = $this->projectionKeys($summary);
        foreach (['itemCount', 'totalAmount', 'selectionHash', 'publicReference', 'gatewayRef', 'invoiceUrl',
            'externalCandidateId', 'externalProcessId', 'assessmentRoundId', 'participantId', 'members'] as $key) {
            $this->assertNotContains($key, $keys);
        }
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach ($privateValues as $value) {
            $this->assertStringNotContainsString($value, $encoded);
            $response->assertDontSee($value);
        }
    }

    /** @param array<array-key,mixed> $value
     * @return list<string>
     */
    private function projectionKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($item)) {
                array_push($keys, ...$this->projectionKeys($item));
            }
        }

        return $keys;
    }

    /** @param TestResponse<Response> $response */
    private function assertPrivateHeaders(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        foreach ([
            'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; base-uri 'none'; frame-ancestors 'none'",
        ] as $header => $value) {
            $this->assertSame($value, $response->headers->get($header));
        }
    }
}
