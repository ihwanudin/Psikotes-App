<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class GenericAssessmentResultProjector
{
    private const array SOURCE_KEYS = [
        'assessmentAttemptId',
        'iq',
        'engineVersion',
        'completedAt',
        'finality',
        'revokedAt',
        'resultVersion',
    ];

    private const array ENVELOPE_KEYS = [
        ...self::SOURCE_KEYS,
        'resultChecksum',
    ];

    /**
     * Build the shared result projection that future callback and poll adapters must use.
     *
     * @param  array<string, mixed>  $authorizedSnapshot
     * @param  array<string, mixed>|null  $previousEnvelope
     * @return array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string}
     */
    public function project(array $authorizedSnapshot, ?array $previousEnvelope = null): array
    {
        $normalized = $this->normalizeSource($authorizedSnapshot);
        $projected = $this->withChecksum($normalized);

        if ($previousEnvelope === null) {
            if ($projected['resultVersion'] !== 1) {
                throw new LogicException('ASSESSMENT_RESULT_VERSION_SEQUENCE_INVALID');
            }

            return $projected;
        }

        $previous = $this->validatePreviousEnvelope($previousEnvelope);

        if ($projected['assessmentAttemptId'] !== $previous['assessmentAttemptId']) {
            throw new LogicException('ASSESSMENT_RESULT_ATTEMPT_CONFLICT');
        }

        if ($projected['resultVersion'] < $previous['resultVersion']) {
            throw new LogicException('ASSESSMENT_RESULT_VERSION_STALE');
        }

        if ($projected['resultVersion'] === $previous['resultVersion']) {
            if (! hash_equals($previous['resultChecksum'], $projected['resultChecksum'])) {
                throw new LogicException('ASSESSMENT_RESULT_VERSION_CONFLICT');
            }

            return $previous;
        }

        if ($projected['resultVersion'] !== $previous['resultVersion'] + 1) {
            throw new LogicException('ASSESSMENT_RESULT_VERSION_SEQUENCE_INVALID');
        }

        if ($this->semanticPayload($projected) === $this->semanticPayload($previous)) {
            throw new LogicException('ASSESSMENT_RESULT_VERSION_NO_CHANGE');
        }

        return $projected;
    }

    /**
     * Safe metadata for the caller's audit event. IQ and raw attempt IDs are excluded.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{event:string,assessmentAttemptReference:string,resultVersion:int,resultChecksum:string,finality:string,isRevoked:bool}
     */
    public function safeAuditContext(array $envelope): array
    {
        $validated = $this->validatePreviousEnvelope($envelope);

        return [
            'event' => 'generic_assessment_result.projected',
            'assessmentAttemptReference' => hash('sha256', $validated['assessmentAttemptId']),
            'resultVersion' => $validated['resultVersion'],
            'resultChecksum' => $validated['resultChecksum'],
            'finality' => $validated['finality'],
            'isRevoked' => $validated['revokedAt'] !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int}
     */
    private function normalizeSource(array $source): array
    {
        $keys = array_keys($source);
        sort($keys);
        $expected = self::SOURCE_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            throw new InvalidArgumentException('ASSESSMENT_RESULT_SOURCE_INVALID');
        }

        $attemptId = $source['assessmentAttemptId'];
        $iq = $source['iq'];
        $engineVersion = $source['engineVersion'];
        $completedAt = $source['completedAt'];
        $finality = $source['finality'];
        $revokedAt = $source['revokedAt'];
        $resultVersion = $source['resultVersion'];

        if (! is_string($attemptId) || ! Str::isUlid($attemptId)
            || (! is_int($iq) && ! is_float($iq)) || ! is_finite((float) $iq)
            || (float) $iq <= 0.0 || (float) $iq > 300.0
            || ! is_string($engineVersion) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+\/-]{0,99}$/', $engineVersion) !== 1
            || ! is_string($completedAt)
            || $finality !== 'FINALIZED'
            || ($revokedAt !== null && ! is_string($revokedAt))
            || ! is_int($resultVersion) || $resultVersion < 1) {
            throw new InvalidArgumentException('ASSESSMENT_RESULT_SOURCE_INVALID');
        }

        $completedAtUtc = $this->normalizeTimestamp($completedAt);
        $revokedAtUtc = $revokedAt === null ? null : $this->normalizeTimestamp($revokedAt);
        if ($completedAtUtc === null || ($revokedAt !== null && $revokedAtUtc === null)
            || ($revokedAtUtc !== null && strcmp($revokedAtUtc, $completedAtUtc) < 0)) {
            throw new InvalidArgumentException('ASSESSMENT_RESULT_SOURCE_INVALID');
        }

        return [
            'assessmentAttemptId' => strtoupper($attemptId),
            'iq' => $iq,
            'engineVersion' => $engineVersion,
            'completedAt' => $completedAtUtc,
            'finality' => $finality,
            'revokedAt' => $revokedAtUtc,
            'resultVersion' => $resultVersion,
        ];
    }

    /**
     * @param  array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int}  $normalized
     * @return array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string}
     */
    private function withChecksum(array $normalized): array
    {
        $encoded = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return [
            ...$normalized,
            'resultChecksum' => hash('sha256', 'generic-assessment-result:v1|'.$encoded),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string}
     */
    private function validatePreviousEnvelope(array $envelope): array
    {
        try {
            $keys = array_keys($envelope);
            sort($keys);
            $expected = self::ENVELOPE_KEYS;
            sort($expected);
            if ($keys !== $expected || ! is_string($envelope['resultChecksum'])
                || preg_match('/^[a-f0-9]{64}$/', $envelope['resultChecksum']) !== 1) {
                throw new LogicException;
            }

            $source = $envelope;
            unset($source['resultChecksum']);
            $expectedEnvelope = $this->withChecksum($this->normalizeSource($source));
            if (! hash_equals($expectedEnvelope['resultChecksum'], $envelope['resultChecksum'])) {
                throw new LogicException;
            }

            return $expectedEnvelope;
        } catch (Throwable) {
            throw new LogicException('ASSESSMENT_RESULT_PREVIOUS_INVALID');
        }
    }

    /** @param array<string, mixed> $envelope */
    private function semanticPayload(array $envelope): string
    {
        unset($envelope['resultVersion'], $envelope['resultChecksum']);

        return json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function normalizeTimestamp(string $timestamp): ?string
    {
        if (preg_match(
            '/^(?<seconds>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d{1,6}))?(?<offset>Z|[+-]\d{2}:\d{2})$/',
            $timestamp,
            $parts,
        ) !== 1) {
            return null;
        }

        try {
            $parseable = $parts['seconds'].'.'.str_pad($parts['fraction'], 6, '0').($parts['offset'] === 'Z' ? '+00:00' : $parts['offset']);
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $parseable);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed === null || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $parsed->format('Y-m-d\TH:i:s.uP') !== $parseable) {
                return null;
            }

            return $parsed->utc()->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
