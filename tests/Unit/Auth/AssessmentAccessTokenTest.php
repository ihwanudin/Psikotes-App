<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Services\ParticipantAuth\AssessmentAccessToken;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\InvalidParticipantToken;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

/** Contract tests: no DB writes, real framework encryption with synthetic keys. */
final class AssessmentAccessTokenTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config()->set('participant_auth.jwt.secret', 'base64:'.base64_encode(str_repeat('A', 32)));
    }

    public function test_round_trip_preserves_only_attempt_identity_and_expires_at_ten_minutes(): void
    {
        $tokens = app(AssessmentAccessToken::class);
        $token = $tokens->issue(new AssessmentPrincipal(41, 7, 81));
        $this->assertStringStartsWith('assessment.v1.', $token);
        $principal = $tokens->verify($token);
        $this->assertSame([41, 7, 81], [$principal->participantId, $principal->organizationId, $principal->assessmentParticipantId]);
        $claims = $this->claims($token);
        $this->assertSame(['version', 'purpose', 'iss', 'aud', 'participant_id', 'organization_id', 'assessment_participant_id', 'iat', 'exp'], array_keys($claims));
        $this->assertSame('assessment-start', $claims['purpose']);
        $this->assertSame(600, $claims['exp'] - $claims['iat']);
        $this->travel(599)->seconds();
        $this->assertSame(81, $tokens->verify($token)->assessmentParticipantId);
        $this->travel(1)->seconds();
        $this->expectException(InvalidParticipantToken::class);
        $tokens->verify($token);
    }

    public function test_valid_legacy_jwt_is_not_an_assessment_credential(): void
    {
        $token = app(ParticipantJwt::class)->issue(41, 7);
        $this->expectException(InvalidParticipantToken::class);
        app(AssessmentAccessToken::class)->verify($token);
    }

    public function test_assessment_token_cannot_be_used_as_a_legacy_jwt(): void
    {
        $token = app(AssessmentAccessToken::class)->issue(new AssessmentPrincipal(41, 7, 81));
        $this->expectException(InvalidParticipantToken::class);
        app(ParticipantJwt::class)->verify($token);
    }

    public function test_tampered_ciphertext_is_rejected_by_framework_authentication(): void
    {
        $token = app(AssessmentAccessToken::class)->issue(new AssessmentPrincipal(41, 7, 81));
        $envelope = json_decode(base64_decode(substr($token, strlen('assessment.v1.'))), true, flags: JSON_THROW_ON_ERROR);
        $envelope['value'][0] = $envelope['value'][0] === 'A' ? 'B' : 'A';
        $tampered = 'assessment.v1.'.base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR));
        $this->expectException(InvalidParticipantToken::class);
        app(AssessmentAccessToken::class)->verify($tampered);
    }

    public function test_ciphertext_authenticated_with_another_key_is_rejected(): void
    {
        $token = app(AssessmentAccessToken::class)->issue(new AssessmentPrincipal(41, 7, 81));
        $other = new Encrypter(str_repeat('B', 32), 'AES-256-CBC');
        $forged = 'assessment.v1.'.$other->encryptString(json_encode($this->claims($token), JSON_THROW_ON_ERROR));
        $this->expectException(InvalidParticipantToken::class);
        app(AssessmentAccessToken::class)->verify($forged);
    }

    #[DataProvider('invalidClaims')]
    public function test_authenticated_but_invalid_claims_are_rejected(array $changes): void
    {
        $tokens = app(AssessmentAccessToken::class);
        $claims = $this->claims($tokens->issue(new AssessmentPrincipal(41, 7, 81)));
        $token = $this->encrypt([...$claims, ...$changes]);
        $this->expectException(InvalidParticipantToken::class);
        $tokens->verify($token);
    }

    public static function invalidClaims(): iterable
    {
        yield 'checkout purpose' => [['purpose' => 'checkout']];
        yield 'missing purpose' => [['purpose' => null]];
        yield 'legacy purpose' => [['purpose' => 'participant']];
        yield 'unknown version' => [['version' => 2]];
        yield 'wrong issuer' => [['iss' => 'https://foreign.example.test/assessment-auth']];
        yield 'wrong audience' => [['aud' => 'https://foreign.example.test/assessment-start']];
        yield 'string participant' => [['participant_id' => '41']];
        yield 'zero participant' => [['participant_id' => 0]];
        yield 'negative tenant' => [['organization_id' => -1]];
        yield 'string attempt' => [['assessment_participant_id' => '81']];
        yield 'missing attempt' => [['assessment_participant_id' => null]];
        yield 'array identity' => [['participant_id' => [41]]];
        yield 'bool identity' => [['participant_id' => true]];
        yield 'paid claim' => [['paid' => true]];
        yield 'expired' => [['exp' => 1]];
        yield 'future issuance' => [['iat' => PHP_INT_MAX, 'exp' => PHP_INT_MAX]];
        yield 'unbounded expiry' => [['exp' => PHP_INT_MAX]];
        yield 'wrong time type' => [['iat' => '123']];
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_and_oversized_credentials_are_rejected(string $token): void
    {
        $this->expectException(InvalidParticipantToken::class);
        app(AssessmentAccessToken::class)->verify($token);
    }

    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown prefix' => ['checkout.v1.anything'];
        yield 'invalid envelope' => ['assessment.v1.anything'];
        yield 'missing envelope' => ['assessment.v1.'];
        yield 'oversized' => ['assessment.v1.'.str_repeat('a', 4096)];
    }

    public function test_authenticated_non_object_json_is_rejected_without_unserialization(): void
    {
        $token = 'assessment.v1.'.app(StringEncrypter::class)->encryptString('null');
        $this->expectException(InvalidParticipantToken::class);
        app(AssessmentAccessToken::class)->verify($token);
    }

    public function test_ttl_exceeding_maximum_by_one_second_is_rejected(): void
    {
        $tokens = app(AssessmentAccessToken::class);
        $claims = $this->claims($tokens->issue(new AssessmentPrincipal(41, 7, 81)));
        $token = $this->encrypt([...$claims, 'exp' => $claims['iat'] + 601]);
        $this->expectException(InvalidParticipantToken::class);
        $tokens->verify($token);
    }

    public function test_missing_claim_is_rejected_without_exposing_the_decryption_exception(): void
    {
        $tokens = app(AssessmentAccessToken::class);
        $claims = $this->claims($tokens->issue(new AssessmentPrincipal(41, 7, 81)));
        unset($claims['purpose']);
        foreach ([$this->encrypt($claims), 'assessment.v1.invalid'] as $invalid) {
            try {
                $tokens->verify($invalid);
                $this->fail('Invalid token accepted.');
            } catch (InvalidParticipantToken $exception) {
                $this->assertSame('', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    private function claims(string $token): array
    {
        return json_decode(app(StringEncrypter::class)->decryptString(substr($token, strlen('assessment.v1.'))), true, flags: JSON_THROW_ON_ERROR);
    }

    private function encrypt(array $claims): string
    {
        return 'assessment.v1.'.app(StringEncrypter::class)->encryptString(json_encode($claims, JSON_THROW_ON_ERROR));
    }
}
