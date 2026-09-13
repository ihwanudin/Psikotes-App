<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use UnexpectedValueException;

final readonly class PersistedIstResult
{
    private const CODES = ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'];

    /**
     * @param  array<string,mixed>  $sessionDefinition
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param  list<array{code:string,rawScore:int,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int|null,hi:int|null}}>  $subtests
     * @param  array{rawTotal:int,iq:int,level:int,sourceScores:list<int>,category:string,band:array{lo:int|null,hi:int|null}}  $iq
     */
    private function __construct(
        public string $publicId,
        public string $createdAt,
        public string $resultContractVersion,
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
     * @param  array<string,mixed>  $parent
     * @param  array<int,array<string,mixed>>  $sources
     */
    public static function hydrate(array $parent, array $sources): self
    {
        try {
            return self::hydrateExact($parent, $sources);
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  array<int,array<string,mixed>>  $sources
     */
    private static function hydrateExact(array $parent, array $sources): self
    {
        $parentId = self::positiveInteger($parent['id'] ?? null);
        $publicId = self::uppercaseUlid($parent['public_id'] ?? null);
        $createdAt = self::timestamp($parent['created_at'] ?? null);
        if (($parent['instrument_code'] ?? null) !== 'ist'
            || ($parent['result_contract_version'] ?? null) !== SealedIstResult::CONTRACT_VERSION
            || ! is_string($parent['result_payload'] ?? null)
            || ! self::sha256($parent['result_checksum'] ?? null)) {
            throw self::invalid();
        }

        $payload = self::canonicalize(self::decodeObject($parent['result_payload']));
        if (array_keys($payload) !== [
            'answersRevision', 'assessmentCaseId', 'attemptNo', 'iq', 'participantId',
            'resultChecksum', 'resultContractVersion', 'scoringSource', 'sealedSourceChecksum',
            'sessionDefinition', 'sessionId', 'sessionPublicId', 'submittedAt', 'subtests',
        ]) {
            throw self::invalid();
        }

        $withoutChecksum = $payload;
        unset($withoutChecksum['resultChecksum']);
        $computedChecksum = hash('sha256', 'sealed-ist-result:v1|'.self::encode(self::canonicalize($withoutChecksum)));
        if (($payload['resultChecksum'] ?? null) !== $computedChecksum
            || $parent['result_checksum'] !== $computedChecksum) {
            throw self::invalid();
        }

        $assessmentCaseId = self::positiveInteger($payload['assessmentCaseId'] ?? null);
        $sessionId = self::positiveInteger($payload['sessionId'] ?? null);
        $participantId = self::positiveInteger($payload['participantId'] ?? null);
        $attemptNo = self::positiveInteger($payload['attemptNo'] ?? null);
        $answersRevision = self::positiveInteger($payload['answersRevision'] ?? null);
        $sessionPublicId = self::uppercaseUlid($payload['sessionPublicId'] ?? null);
        $submittedAt = self::timestamp($payload['submittedAt'] ?? null);
        $sealedSourceChecksum = self::checksum($payload['sealedSourceChecksum'] ?? null);

        if (self::positiveInteger($parent['assessment_case_id'] ?? null) !== $assessmentCaseId
            || self::positiveInteger($parent['session_id'] ?? null) !== $sessionId
            || self::positiveInteger($parent['participant_id'] ?? null) !== $participantId
            || self::uppercaseUlid($parent['session_public_id'] ?? null) !== $sessionPublicId
            || self::positiveInteger($parent['attempt_no'] ?? null) !== $attemptNo
            || self::timestamp($parent['submitted_at'] ?? null) !== $submittedAt
            || self::positiveInteger($parent['answers_revision'] ?? null) !== $answersRevision
            || self::checksum($parent['sealed_source_checksum'] ?? null) !== $sealedSourceChecksum
            || $payload['resultContractVersion'] !== $parent['result_contract_version']) {
            throw self::invalid();
        }

        $definition = self::definition($payload['sessionDefinition'] ?? null);
        if (($parent['session_definition_version'] ?? null) !== $definition['version']
            || ($parent['session_definition_provenance'] ?? null) !== $definition['provenance']
            || ($parent['session_definition_checksum'] ?? null) !== $definition['checksum']
            || ! is_string($parent['session_definition_payload'] ?? null)
            || self::canonicalize(self::decodeObject($parent['session_definition_payload'])) !== $definition) {
            throw self::invalid();
        }

        $scoringSource = self::scoringSource($payload['scoringSource'] ?? null);
        if (self::positiveInteger($parent['instrument_version_id'] ?? null) !== $scoringSource['id']
            || ($parent['instrument_version'] ?? null) !== $scoringSource['version']
            || ($parent['instrument_source_file'] ?? null) !== $scoringSource['sourceFile']
            || ($parent['instrument_checksum'] ?? null) !== $scoringSource['checksum']) {
            throw self::invalid();
        }

        $subtests = self::subtests($payload['subtests'] ?? null);
        $iq = self::iq($payload['iq'] ?? null, $subtests);
        self::sources($sources, $parentId, $createdAt, $subtests);
        $canonicalPayload = self::canonicalize($payload);

        return new self(
            publicId: $publicId,
            createdAt: $createdAt,
            resultContractVersion: SealedIstResult::CONTRACT_VERSION,
            assessmentCaseId: $assessmentCaseId,
            sessionId: $sessionId,
            participantId: $participantId,
            sessionPublicId: $sessionPublicId,
            attemptNo: $attemptNo,
            submittedAt: $submittedAt,
            answersRevision: $answersRevision,
            sealedSourceChecksum: $sealedSourceChecksum,
            sessionDefinition: $definition,
            scoringSource: $scoringSource,
            subtests: $subtests,
            iq: $iq,
            resultChecksum: $computedChecksum,
            canonicalJson: self::encode($canonicalPayload),
        );
    }

    /** @return array<string,mixed> */
    private static function definition(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::invalid();
        }
        $definition = SessionDefinition::fromArray($value);
        if ($definition->instrument !== GenericAssessmentInstrument::Ist) {
            throw self::invalid();
        }
        $canonical = self::canonicalize($definition->toArray());
        if ($canonical !== self::canonicalize($value)) {
            throw self::invalid();
        }

        return $canonical;
    }

    /** @return array{id:int,code:string,version:string,sourceFile:string,checksum:string} */
    private static function scoringSource(mixed $value): array
    {
        if (! is_array($value)
            || array_keys($value) !== ['checksum', 'code', 'id', 'sourceFile', 'version']
            || ($value['code'] ?? null) !== 'ist') {
            throw self::invalid();
        }

        return [
            'id' => self::positiveInteger($value['id'] ?? null),
            'code' => 'ist',
            'version' => self::identity($value['version'] ?? null),
            'sourceFile' => self::identity($value['sourceFile'] ?? null),
            'checksum' => self::checksum($value['checksum'] ?? null),
        ];
    }

    /** @return list<array{code:string,rawScore:int,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int|null,hi:int|null}}> */
    private static function subtests(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 9) {
            throw self::invalid();
        }
        $result = [];
        foreach ($value as $offset => $subtest) {
            if (! is_array($subtest)
                || array_keys($subtest) !== ['band', 'category', 'code', 'level', 'rawScore', 'sourceScore', 'standardScore']
                || ($subtest['code'] ?? null) !== self::CODES[$offset]) {
                throw self::invalid();
            }
            $result[] = [
                'code' => self::identity($subtest['code']),
                'rawScore' => self::nonNegativeInteger($subtest['rawScore'] ?? null),
                'standardScore' => self::integer($subtest['standardScore'] ?? null),
                'sourceScore' => self::integer($subtest['sourceScore'] ?? null),
                'level' => self::level($subtest['level'] ?? null),
                'category' => self::category($subtest['category'] ?? null),
                'band' => self::band($subtest['band'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array{rawScore:int}>  $subtests
     * @return array{rawTotal:int,iq:int,level:int,sourceScores:list<int>,category:string,band:array{lo:int|null,hi:int|null}}
     */
    private static function iq(mixed $value, array $subtests): array
    {
        if (! is_array($value)
            || array_keys($value) !== ['band', 'category', 'iq', 'level', 'rawTotal', 'sourceScores']
            || ! is_array($value['sourceScores'] ?? null)
            || ! array_is_list($value['sourceScores'])
            || $value['sourceScores'] === []) {
            throw self::invalid();
        }
        $scores = array_map(self::integer(...), $value['sourceScores']);
        $rawTotal = self::nonNegativeInteger($value['rawTotal'] ?? null);
        if ($rawTotal !== array_sum(array_column($subtests, 'rawScore'))) {
            throw self::invalid();
        }

        return [
            'rawTotal' => $rawTotal,
            'iq' => self::integer($value['iq'] ?? null),
            'level' => self::level($value['level'] ?? null),
            'sourceScores' => $scores,
            'category' => self::category($value['category'] ?? null),
            'band' => self::band($value['band'] ?? null),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  list<array{code:string,rawScore:int,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int|null,hi:int|null}}>  $subtests
     */
    private static function sources(array $rows, int $parentId, string $createdAt, array $subtests): void
    {
        if (! array_is_list($rows) || count($rows) !== 9) {
            throw self::invalid();
        }
        foreach ($rows as $offset => $row) {
            $expected = $subtests[$offset];
            if (self::positiveInteger($row['id'] ?? null) < 1
                || self::positiveInteger($row['result_id'] ?? null) !== $parentId
                || self::positiveInteger($row['ordinal'] ?? null) !== $offset + 1
                || ($row['source_code'] ?? null) !== $expected['code']
                || self::nonNegativeInteger($row['raw_score'] ?? null) !== $expected['rawScore']
                || self::integer($row['standard_score'] ?? null) !== $expected['standardScore']
                || self::integer($row['source_score'] ?? null) !== $expected['sourceScore']
                || self::level($row['level'] ?? null) !== $expected['level']
                || self::category($row['category'] ?? null) !== $expected['category']
                || self::nullableInteger($row['band_low'] ?? null) !== $expected['band']['lo']
                || self::nullableInteger($row['band_high'] ?? null) !== $expected['band']['hi']
                || self::timestamp($row['created_at'] ?? null) !== $createdAt) {
                throw self::invalid();
            }
        }
    }

    /** @return array{lo:int|null,hi:int|null} */
    private static function band(mixed $value): array
    {
        if (! is_array($value) || array_keys($value) !== ['hi', 'lo']) {
            throw self::invalid();
        }
        $band = ['lo' => self::nullableInteger($value['lo']), 'hi' => self::nullableInteger($value['hi'])];
        if (($band['lo'] === null && $band['hi'] === null)
            || ($band['lo'] !== null && $band['hi'] !== null && $band['lo'] > $band['hi'])) {
            throw self::invalid();
        }

        return $band;
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($value) || array_is_list($value)) {
            throw self::invalid();
        }

        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)$/D', $value) !== 1) {
            throw self::invalid();
        }
        $timestamp = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw self::invalid();
        }

        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function uppercaseUlid(mixed $value): string
    {
        if (! is_string($value) || strtoupper($value) !== $value || ! Str::isUlid($value)) {
            throw self::invalid();
        }

        return $value;
    }

    private static function identity(mixed $value): string
    {
        if (! is_string($value) || $value === '' || $value !== trim($value)
            || preg_match('/[\p{C}\p{Z}\s]/u', $value) !== 0) {
            throw self::invalid();
        }

        return $value;
    }

    private static function category(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw self::invalid();
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $value) !== 1) {
            throw self::invalid();
        }

        return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw self::invalid();
    }

    private static function positiveInteger(mixed $value): int
    {
        $integer = self::integer($value);
        if ($integer < 1) {
            throw self::invalid();
        }

        return $integer;
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        $integer = self::integer($value);
        if ($integer < 0) {
            throw self::invalid();
        }

        return $integer;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : self::integer($value);
    }

    private static function level(mixed $value): int
    {
        $level = self::integer($value);
        if ($level < 1 || $level > 5) {
            throw self::invalid();
        }

        return $level;
    }

    private static function checksum(mixed $value): string
    {
        if (! self::sha256($value)) {
            throw self::invalid();
        }

        return $value;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
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
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw self::invalid();
        }
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('IST_RESULT_READ_INVALID');
    }
}
