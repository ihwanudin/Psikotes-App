<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Services\ParticipantAuth\Exceptions\InvalidParticipantToken;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\Date;
use JsonException;
use LogicException;
use SensitiveParameter;

/** Internal issuance only: callers authenticate scope; this credential never certifies payment or consent. */
final readonly class AssessmentAccessToken
{
    private const string PREFIX = 'assessment.v1.';

    private const int MAX_TTL = 600;

    private const array CLAIMS = ['version', 'purpose', 'iss', 'aud', 'participant_id', 'organization_id', 'assessment_participant_id', 'iat', 'exp'];

    public function __construct(private StringEncrypter $encrypter) {}

    public function issue(AssessmentPrincipal $principal): string
    {
        $issuedAt = Date::now()->getTimestamp();

        return self::PREFIX.$this->encrypter->encryptString(json_encode([
            'version' => 1, 'purpose' => 'assessment-start',
            'iss' => $this->origin().'/assessment-auth', 'aud' => $this->origin().'/assessment-start',
            'participant_id' => $principal->participantId, 'organization_id' => $principal->organizationId,
            'assessment_participant_id' => $principal->assessmentParticipantId,
            'iat' => $issuedAt, 'exp' => $issuedAt + self::MAX_TTL,
        ], JSON_THROW_ON_ERROR));
    }

    public function verify(#[SensitiveParameter] string $token): AssessmentPrincipal
    {
        if (strlen($token) > 4096 || ! str_starts_with($token, self::PREFIX)) {
            throw new InvalidParticipantToken;
        }
        try {
            $claims = json_decode($this->encrypter->decryptString(substr($token, strlen(self::PREFIX))), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidParticipantToken;
        }
        if (! is_array($claims) || count($claims) !== count(self::CLAIMS)
            || array_diff(array_keys($claims), self::CLAIMS) !== []) {
            throw new InvalidParticipantToken;
        }
        foreach (['participant_id', 'organization_id', 'assessment_participant_id', 'iat', 'exp'] as $key) {
            if (! is_int($claims[$key])) {
                throw new InvalidParticipantToken;
            }
        }
        $now = Date::now()->getTimestamp();
        if ($claims['version'] !== 1 || $claims['purpose'] !== 'assessment-start'
            || $claims['iss'] !== $this->origin().'/assessment-auth' || $claims['aud'] !== $this->origin().'/assessment-start'
            || min($claims['participant_id'], $claims['organization_id'], $claims['assessment_participant_id']) < 1
            || $claims['iat'] < 0 || $claims['iat'] > $now || $claims['exp'] <= $now
            || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > self::MAX_TTL) {
            throw new InvalidParticipantToken;
        }

        return new AssessmentPrincipal($claims['participant_id'], $claims['organization_id'], $claims['assessment_participant_id']);
    }

    private function origin(): string
    {
        $url = config('app.url');
        if (! is_string($url) || trim($url) === '') {
            throw new LogicException('Assessment credential requires a configured application URL.');
        }

        return rtrim($url, '/');
    }
}
