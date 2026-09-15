<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Controllers\StartParticipantSessionController;
use App\Http\Middleware\AuthenticateAssessmentToken;
use App\Http\Requests\StartAssessmentSessionRequest;
use App\Models\Entitlement;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentAccessToken;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantEntitlementGate;
use App\Services\ParticipantAuth\ParticipantJwt;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

/** Test-only route: no production issuer, route alias, or session engine is installed. */
final class AssessmentSessionAuthorizationTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private const string URL = '/__testing/assessment/sessions/ist/start';

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config()->set('participant_auth.jwt.secret', 'base64:'.base64_encode(str_repeat('A', 32)));
        $this->f = Fixture::create();
        Route::post('/__testing/assessment/sessions/{testType}/start', StartParticipantSessionController::class)
            ->middleware(AuthenticateAssessmentToken::class);
    }

    public function test_ready_attempt_still_returns_pending_engine_without_starting_or_notifying(): void
    {
        $token = $this->token();
        foreach ([1, 2] as $retry) {
            $this->withToken($token)->postJson(self::URL)->assertStatus(501)
                ->assertExactJson(['error' => ['code' => 'SESSION_ENGINE_PENDING',
                    'message' => 'Akses tes aktif, tetapi mesin sesi belum tersedia pada fase ini.']]);
        }
        $this->assertDatabaseHas('assessment_entitlements', ['id' => $this->f['entitlement'], 'status' => 'ready', 'started_at' => null]);
        $this->assertDatabaseHas('assessment_participants', ['id' => $this->f['attempt'], 'assessment_status' => 'READY']);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    #[DataProvider('revocations')]
    public function test_each_request_reloads_authorization_after_persisted_state_changes(string $table, string $key, array $changes): void
    {
        $token = $this->token();
        $this->withToken($token)->postJson(self::URL)->assertStatus(501);
        DB::table($table)->where($key === 'participant' ? 'participant_id' : 'id', $this->f[$key])->update($changes);
        $this->withToken($token)->postJson(self::URL)->assertForbidden()->assertJsonPath('error.code', 'ENTITLEMENT_LOCKED');
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public static function revocations(): iterable
    {
        yield 'unpaid bill' => ['assessment_bills', 'bill', ['status' => 'pending', 'paid_at' => null]];
        yield 'allocation removed' => ['assessment_bill_items', 'item', ['settled_at' => null]];
        yield 'revoked attempt' => ['assessment_participants', 'attempt', ['revoked_at' => '2026-01-01 00:00:00']];
        yield 'finalized attempt' => ['assessment_participants', 'attempt', ['finalized_at' => '2026-01-01 00:00:00']];
        yield 'not activated' => ['assessment_participants', 'attempt', ['assessment_status' => 'PROVISIONED']];
        yield 'legacy marker' => ['assessment_participants', 'attempt', ['metadata' => '{}']];
        yield 'locked right' => ['assessment_entitlements', 'entitlement', ['status' => 'locked']];
        yield 'started right' => ['assessment_entitlements', 'entitlement', ['started_at' => '2026-01-01 00:00:00']];
        yield 'withdrawn consent' => ['consent_records', 'participant', ['withdrawn_at' => '2026-01-01 00:00:00']];
        yield 'changed consent version' => ['consent_records', 'participant', ['document_version' => 'obsolete']];
        yield 'changed identity outcome' => ['identity_verifications', 'participant', ['outcome' => 'mismatch']];
        yield 'manual rejected identity' => ['identity_verifications', 'participant', ['manual_status' => 'rejected']];
        yield 'new evidence needs recheck' => ['identity_evidence', 'participant', ['updated_at' => '2099-01-01 00:00:00']];
    }

    public function test_deleted_participant_invalidates_previously_valid_token(): void
    {
        $token = $this->token();
        $this->withToken($token)->postJson(self::URL)->assertStatus(501);
        DB::table('participants')->where('id', $this->f['participant'])->update(['deleted_at' => now()]);
        $this->withToken($token)->postJson(self::URL)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_deleted_attempt_invalidates_previously_valid_token(): void
    {
        $token = $this->token();
        $this->withToken($token)->postJson(self::URL)->assertStatus(501);
        // Respect composite FK constraints while deleting this synthetic attempt's dependents.
        DB::table('assessment_entitlements')
            ->where('assessment_participant_id', $this->f['attempt'])->delete();
        DB::table('assessment_bill_items')->where('id', $this->f['item'])->delete();
        DB::table('assessment_charges')->where('id', $this->f['charge'])->delete();
        DB::table('assessment_participants')->where('id', $this->f['attempt'])->delete();
        $this->withToken($token)->postJson(self::URL)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_authenticated_but_foreign_or_missing_scope_is_rejected(): void
    {
        $other = Fixture::create();
        $sibling = Fixture::create(identity: ['organization' => $this->f['organization']]);
        foreach ([
            [$this->f['participant'], $other['organization'], $this->f['attempt']],
            [$this->f['participant'], $this->f['organization'], $other['attempt']],
            [$this->f['participant'], $this->f['organization'], $sibling['attempt']],
            [$other['participant'], $this->f['organization'], $this->f['attempt']],
            [$this->f['participant'], $this->f['organization'], 999999],
        ] as $ids) {
            $token = app(AssessmentAccessToken::class)->issue(new AssessmentPrincipal(...$ids));
            $this->withToken($token)->postJson(self::URL)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOKEN');
        }
    }

    public function test_paid_old_attempt_and_legacy_right_cannot_authorize_new_unpaid_attempt(): void
    {
        $next = Fixture::create(identity: $this->f);
        DB::table('assessment_bills')->where('id', $next['bill'])->update(['status' => 'pending', 'paid_at' => null]);
        $this->createCaseScopedGenericEntitlement();
        $this->withToken($this->token())->postJson(self::URL)->assertStatus(501);
        $this->withToken($this->token($next))->postJson(self::URL)->assertForbidden();
        $legacy = app(ParticipantJwt::class)->issue($this->f['participant'], $this->f['organization']);
        $this->withToken($legacy)->postJson(self::URL)->assertUnauthorized();
        $this->withToken($legacy)->postJson('/api/sessions/ist/start')->assertStatus(501)->assertJsonPath('error.code', 'SESSION_ENGINE_PENDING');
        $this->withToken($legacy)->postJson('/api/sessions/ist/start', ['unrelatedLegacyField' => true])->assertStatus(501);
        $this->withToken($legacy)->postJson('/api/sessions/ist/start', ['assessment_participant_id' => $next['attempt']])->assertUnprocessable();
        $this->withToken($this->token())->postJson('/api/sessions/ist/start')->assertUnauthorized();
    }

    public function test_withdrawn_dass_consent_does_not_block_main_test_but_blocks_dass(): void
    {
        $dass = Fixture::create('dass21', $this->f);
        $this->withToken($this->token($dass))->postJson('/__testing/assessment/sessions/dass21/start')->assertStatus(501);
        DB::table('consent_records')->where('participant_id', $this->f['participant'])
            ->where('consent_type', 'dass')->update(['withdrawn_at' => now()]);
        $this->withToken($this->token($dass))->postJson('/__testing/assessment/sessions/dass21/start')->assertForbidden();
        $this->withToken($this->token())->postJson(self::URL)->assertStatus(501);
    }

    public function test_unsigned_or_checkout_credentials_are_rejected_with_sanitized_errors(): void
    {
        $token = $this->token();
        $claims = json_decode(app(StringEncrypter::class)->decryptString(substr($token, strlen('assessment.v1.'))), true, flags: JSON_THROW_ON_ERROR);
        $foreign = new Encrypter(str_repeat('B', 32), 'AES-256-CBC');
        foreach ([
            'assessment.v1.invalid',
            'assessment.v1.'.$foreign->encryptString(json_encode($claims, JSON_THROW_ON_ERROR)),
            'assessment.v1.'.app(StringEncrypter::class)->encryptString(json_encode([...$claims, 'purpose' => 'checkout'], JSON_THROW_ON_ERROR)),
            'assessment.v1.'.str_repeat('a', 4096),
        ] as $invalid) {
            $response = $this->withToken($invalid)->postJson(self::URL)->assertUnauthorized();
            $response->assertExactJson(['error' => ['code' => 'INVALID_TOKEN', 'message' => 'Token peserta tidak valid atau telah kedaluwarsa.']]);
            $this->assertStringNotContainsString($invalid, $response->getContent());
        }
    }

    public function test_expiry_and_future_issuance_are_enforced_at_http_boundary(): void
    {
        $token = $this->token();
        $this->travel(-1)->seconds();
        $this->withToken($token)->postJson(self::URL)->assertUnauthorized();
        $this->travel(600)->seconds();
        $this->withToken($token)->postJson(self::URL)->assertStatus(501);
        $this->travel(1)->seconds();
        $this->withToken($token)->postJson(self::URL)->assertUnauthorized();
    }

    public function test_credentials_are_only_read_from_authorization_header(): void
    {
        $token = $this->token();
        $this->postJson(self::URL, ['token' => $token])->assertUnauthorized();
        $this->postJson(self::URL.'?token='.rawurlencode($token))->assertUnauthorized();
        $this->withUnencryptedCookie('token', $token)->postJson(self::URL)->assertUnauthorized();
        foreach (['Basic '.$token, 'Bearer '.$token.', Bearer '.$token, 'Bearer '.$token.' extra'] as $header) {
            $this->withHeader('Authorization', $header)->postJson(self::URL)->assertUnauthorized();
        }
    }

    public function test_browser_scope_and_unpurchased_type_are_rejected_and_host_is_not_an_issuer(): void
    {
        $token = $this->token();
        $this->withToken($token)->withHeader('Host', 'foreign.example.test')->postJson(self::URL)->assertStatus(501);
        foreach (['assessment_participant_id', 'assessmentParticipantId', 'organization_id', 'participant_id', 'testType'] as $key) {
            $this->withToken($token)->postJson(self::URL, [$key => 999])->assertUnprocessable();
        }
        $this->withToken($token)->postJson(self::URL.'?assessment_participant_id=999')->assertUnprocessable();
        $this->withToken($token)->postJson('/__testing/assessment/sessions/papi/start')->assertForbidden();
        $this->withToken($token)->postJson('/__testing/assessment/sessions/unknown/start')->assertUnprocessable();
    }

    public function test_middleware_discards_stale_principals_and_never_falls_back_to_them(): void
    {
        $request = Request::create(self::URL, 'POST');
        $request->attributes->set('assessment_principal', new AssessmentPrincipal($this->f['participant'], $this->f['organization'], $this->f['attempt']));
        $legacy = new ParticipantPrincipal($this->f['participant'], $this->f['organization']);
        $request->attributes->set('participant_principal', $legacy);
        $request->setUserResolver(fn () => $legacy);
        $response = app(AuthenticateAssessmentToken::class)->handle($request, function () {
            $this->fail('Missing bearer must not invoke the controller.');
        });
        $this->assertSame(401, $response->getStatusCode());
        $this->assertNull($request->user());
        $this->assertFalse($request->attributes->has('assessment_principal'));
        $this->assertFalse($request->attributes->has('participant_principal'));

        $other = Fixture::create();
        $request->headers->set('Authorization', 'Bearer '.$this->token($other));
        app(AuthenticateAssessmentToken::class)->handle($request, function (Request $request) use ($other) {
            $principal = $request->attributes->get('assessment_principal');
            $this->assertSame($other['attempt'], $principal->assessmentParticipantId);
            $this->assertSame($principal, $request->user());
            $this->assertNull(app(RlsContextRunner::class)->current());

            return response()->json([]);
        });
    }

    public function test_controller_does_not_fall_back_from_malformed_assessment_principal_to_legacy(): void
    {
        $this->createCaseScopedGenericEntitlement();
        $request = StartAssessmentSessionRequest::create(self::URL, 'POST');
        $request->attributes->set('assessment_principal', ['participantId' => $this->f['participant']]);
        $request->attributes->set('participant_principal', new ParticipantPrincipal($this->f['participant'], $this->f['organization']));
        $this->expectException(UnauthorizedHttpException::class);
        app(StartParticipantSessionController::class)($request, 'ist', app(ParticipantEntitlementGate::class),
            app(AssessmentEntitlementGate::class), app(RlsContextRunner::class));
    }

    public function test_duplicate_authorization_headers_are_rejected_instead_of_selecting_one(): void
    {
        $request = Request::create(self::URL, 'POST');
        $request->headers->set('Authorization', ['Bearer '.$this->token(), 'Bearer invalid']);
        $response = app(AuthenticateAssessmentToken::class)->handle($request, function () {
            $this->fail('Ambiguous credentials must not invoke the controller.');
        });
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_controller_rechecks_stale_principal_instead_of_trusting_middleware_history(): void
    {
        $request = StartAssessmentSessionRequest::create(self::URL, 'POST');
        $request->attributes->set('assessment_principal', new AssessmentPrincipal($this->f['participant'], $this->f['organization'], $this->f['attempt']));
        DB::table('assessment_participants')->where('id', $this->f['attempt'])->update(['revoked_at' => now()]);
        $response = app(StartParticipantSessionController::class)($request, 'ist', app(ParticipantEntitlementGate::class),
            app(AssessmentEntitlementGate::class), app(RlsContextRunner::class));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ENTITLEMENT_LOCKED', $response->getData(true)['error']['code']);
    }

    private function token(?array $f = null): string
    {
        $f ??= $this->f;

        return app(AssessmentAccessToken::class)->issue(new AssessmentPrincipal($f['participant'], $f['organization'], $f['attempt']));
    }

    private function createCaseScopedGenericEntitlement(): void
    {
        DB::table('participants')->where('id', $this->f['participant'])->update([
            'package_id' => $this->f['package'],
            'source_system' => 'P6B_TEST',
        ]);
        Entitlement::query()->create([
            'participant_id' => $this->f['participant'],
            'assessment_case_id' => $this->f['case'],
            'test_type' => 'ist',
            'status' => 'ready',
        ]);
    }
}
