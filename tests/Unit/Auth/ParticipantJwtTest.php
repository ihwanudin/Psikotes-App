<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Services\ParticipantAuth\Exceptions\InvalidParticipantToken;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class ParticipantJwtTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('participant_auth.jwt.secret', str_repeat('test-secret-', 4));
        Date::setTestNow('2026-08-25 10:00:00+07:00');
    }

    public function test_token_is_valid_for_twelve_hours_and_contains_only_scoped_identity_claims(): void
    {
        $jwt = app(ParticipantJwt::class);
        $token = $jwt->issue(participantId: 41, branchId: 7);
        $principal = $jwt->verify($token);

        $this->assertSame(41, $principal->participantId);
        $this->assertSame(7, $principal->branchId);
        $this->assertStringNotContainsString('birth', $token);
        $this->assertStringNotContainsString('test_number', $token);

        Date::setTestNow('2026-08-25 21:59:59+07:00');
        $this->assertSame(41, $jwt->verify($token)->participantId);
    }

    public function test_token_expires_after_twelve_hours(): void
    {
        $jwt = app(ParticipantJwt::class);
        $token = $jwt->issue(participantId: 41, branchId: 7);
        Date::setTestNow('2026-08-25 22:00:00+07:00');

        $this->expectException(InvalidParticipantToken::class);

        $jwt->verify($token);
    }

    public function test_tampered_participant_claim_is_rejected(): void
    {
        $jwt = app(ParticipantJwt::class);
        [$header, $payload, $signature] = explode('.', $jwt->issue(participantId: 41, branchId: 7));
        $claims = json_decode($this->decode($payload), true, flags: JSON_THROW_ON_ERROR);
        $claims['participant_id'] = 99;
        $claims['sub'] = '99';
        $tamperedPayload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidParticipantToken::class);

        $jwt->verify($header.'.'.$tamperedPayload.'.'.$signature);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        $this->assertIsString($decoded);

        return $decoded;
    }
}
