<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use JsonException;
use UnexpectedValueException;

final readonly class SealedIstResult
{
    // ADR-0032 PR1 (2026-09-22): bumped v1->v2, payload gained `engineVersion`.
    public const CONTRACT_VERSION = 'ist-result:v2';

    /**
     * The scoring CODE version, distinct from `scoringSource` (the
     * instrument DATA version -- norms/keys) and from `resultContractVersion`
     * (the JSON shape). Bump this whenever IstRawScoreCalculator's rules
     * change, alongside SCORING_ALGORITHM.md's own contract version and
     * CHANGELOG.md, per CLAUDE.md. v1 already reflects the ADR-0032 P1 rule
     * (a blank item scores as wrong) -- that rule shipped in the same change
     * that introduced this constant, so there is no pre-P1 version to track.
     */
    public const ENGINE_VERSION = 'ist-scoring:v1';

    /**
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param list<array{
     *     code:string,
     *     rawScore:int,
     *     standardScore:int,
     *     sourceScore:int,
     *     level:int,
     *     category:string,
     *     band:array{lo:int|null,hi:int|null}
     * }> $subtests
     * @param array{
     *     rawTotal:int,
     *     iq:int,
     *     level:int,
     *     sourceScores:list<int>,
     *     category:string,
     *     band:array{lo:int|null,hi:int|null}
     * } $iq
     * @param  array<string,mixed>  $sessionDefinition
     */
    private function __construct(
        public string $resultContractVersion,
        public string $engineVersion,
        public int $assessmentCaseId,
        public int $sessionId,
        public int $participantId,
        public string $sessionPublicId,
        public int $attemptNo,
        public string $submittedAt,
        public int $answersRevision,
        public string $sealedSourceChecksum,
        public array $sessionDefinition,
        public array $scoringSource,
        public array $subtests,
        public array $iq,
        public string $resultChecksum,
        private string $canonicalJson,
    ) {}

    /**
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param list<array{
     *     code:string,
     *     rawScore:int,
     *     standardScore:int,
     *     sourceScore:int,
     *     level:int,
     *     category:string,
     *     band:array{lo:int|null,hi:int|null}
     * }> $subtests
     * @param array{
     *     rawTotal:int,
     *     iq:int,
     *     level:int,
     *     sourceScores:list<int>,
     *     category:string,
     *     band:array{lo:int|null,hi:int|null}
     * } $iq
     */
    public static function seal(
        SealedGenericAnswerSet $source,
        array $scoringSource,
        array $subtests,
        array $iq,
    ): self {
        self::assertScoringSource($scoringSource);
        self::assertSubtests($subtests);
        self::assertIq($iq, $subtests);

        $payload = self::canonicalize([
            'resultContractVersion' => self::CONTRACT_VERSION,
            'engineVersion' => self::ENGINE_VERSION,
            'assessmentCaseId' => $source->assessmentCaseId,
            'sessionId' => $source->sessionId,
            'participantId' => $source->participantId,
            'sessionPublicId' => $source->sessionPublicId,
            'attemptNo' => $source->attemptNo,
            'submittedAt' => $source->submittedAt,
            'answersRevision' => $source->answersRevision,
            'sealedSourceChecksum' => $source->sourceChecksum,
            'sessionDefinition' => $source->definition->toArray(),
            'scoringSource' => $scoringSource,
            'subtests' => $subtests,
            'iq' => $iq,
        ]);
        $payloadJson = self::encode($payload);
        $resultChecksum = hash('sha256', 'sealed-ist-result:v1|'.$payloadJson);
        $canonicalJson = self::encode(self::canonicalize([
            ...$payload,
            'resultChecksum' => $resultChecksum,
        ]));

        return new self(
            resultContractVersion: self::CONTRACT_VERSION,
            engineVersion: self::ENGINE_VERSION,
            assessmentCaseId: $source->assessmentCaseId,
            sessionId: $source->sessionId,
            participantId: $source->participantId,
            sessionPublicId: $source->sessionPublicId,
            attemptNo: $source->attemptNo,
            submittedAt: $source->submittedAt,
            answersRevision: $source->answersRevision,
            sealedSourceChecksum: $source->sourceChecksum,
            sessionDefinition: $source->definition->toArray(),
            scoringSource: $scoringSource,
            subtests: $subtests,
            iq: $iq,
            resultChecksum: $resultChecksum,
            canonicalJson: $canonicalJson,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'resultContractVersion' => $this->resultContractVersion,
            'engineVersion' => $this->engineVersion,
            'assessmentCaseId' => $this->assessmentCaseId,
            'sessionId' => $this->sessionId,
            'participantId' => $this->participantId,
            'sessionPublicId' => $this->sessionPublicId,
            'attemptNo' => $this->attemptNo,
            'submittedAt' => $this->submittedAt,
            'answersRevision' => $this->answersRevision,
            'sealedSourceChecksum' => $this->sealedSourceChecksum,
            'sessionDefinition' => $this->sessionDefinition,
            'scoringSource' => $this->scoringSource,
            'subtests' => $this->subtests,
            'iq' => $this->iq,
            'resultChecksum' => $this->resultChecksum,
        ];
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    /** @param array<string,mixed> $source */
    private static function assertScoringSource(array $source): void
    {
        if (array_keys($source) !== ['id', 'code', 'version', 'sourceFile', 'checksum']
            || ! is_int($source['id']) || $source['id'] < 1
            || $source['code'] !== 'ist'
            || ! self::canonicalIdentity($source['version'])
            || ! self::canonicalIdentity($source['sourceFile'])
            || preg_match('/\A[a-f0-9]{64}\z/', $source['checksum']) !== 1) {
            throw self::invalid();
        }
    }

    /** @param array<mixed> $subtests */
    private static function assertSubtests(array $subtests): void
    {
        if (! array_is_list($subtests) || count($subtests) !== 9) {
            throw self::invalid();
        }

        $seen = [];
        foreach ($subtests as $subtest) {
            if (! is_array($subtest)
                || array_keys($subtest) !== [
                    'code', 'rawScore', 'standardScore', 'sourceScore', 'level', 'category', 'band',
                ]
                || ! self::canonicalIdentity($subtest['code'])
                || isset($seen[$subtest['code']])
                || ! is_int($subtest['rawScore']) || $subtest['rawScore'] < 0
                || ! is_int($subtest['standardScore'])
                || ! is_int($subtest['sourceScore'])
                || ! is_int($subtest['level']) || $subtest['level'] < 1 || $subtest['level'] > 5
                || ! is_string($subtest['category']) || trim($subtest['category']) === ''
                || ! self::validBand($subtest['band'])) {
                throw self::invalid();
            }
            $seen[$subtest['code']] = true;
        }
    }

    /**
     * @param  array<mixed>  $iq
     * @param  list<array<string,mixed>>  $subtests
     */
    private static function assertIq(array $iq, array $subtests): void
    {
        if (array_keys($iq) !== ['rawTotal', 'iq', 'level', 'sourceScores', 'category', 'band']
            || ! is_int($iq['rawTotal']) || $iq['rawTotal'] < 0
            || $iq['rawTotal'] !== array_sum(array_column($subtests, 'rawScore'))
            || ! is_int($iq['iq'])
            || ! is_int($iq['level']) || $iq['level'] < 1 || $iq['level'] > 5
            || ! array_is_list($iq['sourceScores']) || $iq['sourceScores'] === []
            || array_filter($iq['sourceScores'], static fn (mixed $score): bool => ! is_int($score)) !== []
            || ! is_string($iq['category']) || trim($iq['category']) === ''
            || ! self::validBand($iq['band'])) {
            throw self::invalid();
        }
    }

    private static function validBand(mixed $band): bool
    {
        return is_array($band)
            && array_keys($band) === ['lo', 'hi']
            && (is_int($band['lo']) || $band['lo'] === null)
            && (is_int($band['hi']) || $band['hi'] === null)
            && ($band['lo'] !== null || $band['hi'] !== null)
            && (! is_int($band['lo']) || ! is_int($band['hi']) || $band['lo'] <= $band['hi']);
    }

    private static function canonicalIdentity(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && preg_match('/[\p{C}\p{Z}\s]/u', $value) === 0;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }

        return $value;
    }

    private static function encode(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw self::invalid();
        }
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('SEALED_IST_RESULT_INVALID');
    }
}
