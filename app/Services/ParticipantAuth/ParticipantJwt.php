<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Services\ParticipantAuth\Exceptions\InvalidParticipantToken;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use JsonException;
use LogicException;

final class ParticipantJwt
{
    private const int MAX_TOKEN_LENGTH = 4096;

    public function issue(int $participantId, int $branchId): string
    {
        if ($participantId < 1 || $branchId < 1) {
            throw new LogicException('Participant JWT identifiers must be positive.');
        }

        $issuedAt = Date::now()->getTimestamp();
        $ttl = $this->ttl();
        $header = ['alg' => 'HS256', 'typ' => 'participant+jwt'];
        $payload = [
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'sub' => (string) $participantId,
            'participant_id' => $participantId,
            'branch_id' => $branchId,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + $ttl,
            'jti' => (string) Str::uuid(),
        ];
        $encodedHeader = $this->encodeJson($header);
        $encodedPayload = $this->encodeJson($payload);
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        return $signingInput.'.'.$this->encode(hash_hmac('sha256', $signingInput, $this->secret(), true));
    }

    public function verify(string $token): ParticipantPrincipal
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new InvalidParticipantToken;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new InvalidParticipantToken;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $signature = $this->decode($encodedSignature);
        $expected = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true);

        if (! hash_equals($expected, $signature)) {
            throw new InvalidParticipantToken;
        }

        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedPayload);

        if ($header !== ['alg' => 'HS256', 'typ' => 'participant+jwt']) {
            throw new InvalidParticipantToken;
        }

        $this->assertClaims($claims);

        return new ParticipantPrincipal($claims['participant_id'], $claims['branch_id']);
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims): void
    {
        $requiredStrings = ['iss', 'aud', 'sub', 'jti'];
        $requiredIntegers = ['participant_id', 'branch_id', 'iat', 'nbf', 'exp'];

        foreach ($requiredStrings as $claim) {
            if (! isset($claims[$claim]) || ! is_string($claims[$claim]) || $claims[$claim] === '') {
                throw new InvalidParticipantToken;
            }
        }

        foreach ($requiredIntegers as $claim) {
            if (! isset($claims[$claim]) || ! is_int($claims[$claim])) {
                throw new InvalidParticipantToken;
            }
        }

        $now = Date::now()->getTimestamp();
        $skew = (int) config('participant_auth.jwt.clock_skew_seconds', 30);

        if ($claims['iss'] !== $this->issuer()
            || $claims['aud'] !== $this->audience()
            || $claims['sub'] !== (string) $claims['participant_id']
            || $claims['participant_id'] < 1
            || $claims['branch_id'] < 1
            || $claims['iat'] > $now + $skew
            || $claims['nbf'] > $now + $skew
            || $claims['exp'] <= $now
            || $this->ttl() !== $claims['exp'] - $claims['iat']) {
            throw new InvalidParticipantToken;
        }
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        return $this->encode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $value): array
    {
        try {
            $decoded = json_decode($this->decode($value), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidParticipantToken;
        }

        if (! is_array($decoded)) {
            throw new InvalidParticipantToken;
        }

        return $decoded;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new InvalidParticipantToken;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);

        if (! is_string($decoded)) {
            throw new InvalidParticipantToken;
        }

        return $decoded;
    }

    private function secret(): string
    {
        $configured = (string) config('participant_auth.jwt.secret');

        if (! str_starts_with($configured, 'base64:')) {
            throw new LogicException('PARTICIPANT_JWT_SECRET must be a base64-encoded random key.');
        }

        $secret = base64_decode(substr($configured, 7), true);

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new LogicException('PARTICIPANT_JWT_SECRET must decode to at least 32 bytes.');
        }

        return $secret;
    }

    private function ttl(): int
    {
        return (int) config('participant_auth.jwt.ttl_seconds', 43_200);
    }

    private function issuer(): string
    {
        return (string) config('participant_auth.jwt.issuer');
    }

    private function audience(): string
    {
        return (string) config('participant_auth.jwt.audience');
    }
}
