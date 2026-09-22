<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use JsonException;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), mirrors app/Domain/AssessmentResults/SealedIstResult.php.
 *
 * Field-semantics mapping for RMIB (documented in full at
 * tasks/handoffs/f2/generic-instrument-result-field-mapping.md), derived
 * entirely from app/Services/Scoring/RmibRawScoreCalculator.php,
 * RmibRankLevelCalculator.php, and the new RmibScoreCalculator.php — nothing
 * here invents a new psychometric value except the degenerate band noted
 * below, which is documented as exactly that and nothing more:
 * - `code` = the category's short code (e.g. "Out", "S.Se") — this is
 *   deliberately the SAME short code database/seeders/data/aspect_sources.json
 *   uses to reference RMIB categories (as `RMIB_<code>`), not the long name;
 *   using the long name here would make the aggregator silently fail to find
 *   a source rather than error.
 * - `rawScore` = the category's rank total (sum of nine ranks, 9-108)
 * - `standardScore` = the category's competition rank (1-12; ties share a
 *   rank, per SCORING_ALGORITHM.md's competition-ranking rule)
 * - `sourceScore` = the Sheet 11 rank->score lookup value (1-10)
 * - `level` = the Sheet 11 rank->level lookup value (1-5)
 * - `category` = the category's long descriptive name (e.g. "Outdoor")
 * - `band` = `[rank, rank]`, ALWAYS equal low/high. RMIB has no norm/zone
 *   concept anywhere in SCORING_ALGORITHM.md or its calculators (unlike IST's
 *   score bands or PAPI's white zones). This degenerate band exists ONLY to
 *   satisfy the ledger's `band_low IS NOT NULL OR band_high IS NOT NULL`
 *   check constraint; it is not a range and must never be read as one. A G7
 *   aggregator must never compare `band` for RMIB sources.
 *
 * All twelve categories are persisted, including the seven that are not one
 * of D1-D5: SCORING_ALGORITHM.md ("tujuh kategori lain disimpan untuk
 * tinjauan psikolog") requires they stay visible, mirroring PAPI's G/I/X/Z
 * treatment.
 */
final readonly class SealedRmibResult
{
    // ADR-0032 PR1 (2026-09-22): bumped v1->v2, payload gained `engineVersion`.
    // ADR-0032 PR3 (2026-09-23): bumped v2->v3, payload gained
    // `reviewRequired`/`excludedGroups` (P3's tiered incomplete-ranking rule).
    public const CONTRACT_VERSION = 'rmib-result:v3';

    /**
     * The scoring CODE version -- see SealedIstResult::ENGINE_VERSION's
     * docblock for the distinction from `scoringSource`/`resultContractVersion`.
     * Bump alongside RmibRawScoreCalculator's rules, SCORING_ALGORITHM.md,
     * and CHANGELOG.md. ADR-0032 PR3 bumps this v1->v2: the actual scoring
     * RULE changed (single-missing-position reconstruction, single-group
     * exclusion), not just orchestration around an unchanged formula.
     */
    public const ENGINE_VERSION = 'rmib-scoring:v2';

    public const CATEGORY_COUNT = 12;

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
     * }> $categories
     * @param  array<string,mixed>  $sessionDefinition
     * @param  list<int>  $excludedGroups
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
        public array $categories,
        public bool $reviewRequired,
        public array $excludedGroups,
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
     * }> $categories
     * @param  list<int>  $excludedGroups
     */
    public static function seal(
        SealedGenericAnswerSet $source,
        array $scoringSource,
        array $categories,
        bool $reviewRequired,
        array $excludedGroups,
    ): self {
        self::assertScoringSource($scoringSource);
        self::assertCategories($categories);
        self::assertExcludedGroups($excludedGroups, $reviewRequired);

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
            'categories' => $categories,
            'reviewRequired' => $reviewRequired,
            'excludedGroups' => $excludedGroups,
        ]);
        $payloadJson = self::encode($payload);
        $resultChecksum = hash('sha256', 'sealed-rmib-result:v1|'.$payloadJson);
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
            categories: $categories,
            reviewRequired: $reviewRequired,
            excludedGroups: $excludedGroups,
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
            'categories' => $this->categories,
            'reviewRequired' => $this->reviewRequired,
            'excludedGroups' => $this->excludedGroups,
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
            || $source['code'] !== 'rmib'
            || ! self::canonicalIdentity($source['version'])
            || ! self::canonicalIdentity($source['sourceFile'])
            || preg_match('/\A[a-f0-9]{64}\z/', $source['checksum']) !== 1) {
            throw self::invalid();
        }
    }

    /** @param array<mixed> $categories */
    private static function assertCategories(array $categories): void
    {
        if (! array_is_list($categories) || count($categories) !== self::CATEGORY_COUNT) {
            throw self::invalid();
        }

        $seen = [];
        foreach ($categories as $category) {
            if (! is_array($category)
                || array_keys($category) !== [
                    'code', 'rawScore', 'standardScore', 'sourceScore', 'level', 'category', 'band',
                ]
                || ! self::canonicalIdentity($category['code'])
                || isset($seen[$category['code']])
                // ADR-0032 PR3: lower bound was 9 (all 9 groups always
                // contributed). P3's single-group-exclusion tier can drop a
                // category's cell_count to 8 (min possible rawScore 8*1=8),
                // so the floor widens accordingly -- 108 (9*12) stays the
                // ceiling since a fully complete/reconstructed result is
                // still possible and most common.
                || ! is_int($category['rawScore']) || $category['rawScore'] < 8 || $category['rawScore'] > 108
                || ! is_int($category['standardScore']) || $category['standardScore'] < 1 || $category['standardScore'] > 12
                || ! is_int($category['sourceScore']) || $category['sourceScore'] < 1 || $category['sourceScore'] > 10
                || ! is_int($category['level']) || $category['level'] < 1 || $category['level'] > 5
                || ! is_string($category['category']) || trim($category['category']) === ''
                // Degenerate band: always exactly [rank, rank]. See class docblock.
                || ! self::validDegenerateBand($category['band'], $category['standardScore'])) {
                throw self::invalid();
            }
            $seen[$category['code']] = true;
        }
    }

    /** @param array<mixed> $excludedGroups */
    private static function assertExcludedGroups(array $excludedGroups, bool $reviewRequired): void
    {
        if (! array_is_list($excludedGroups) || count($excludedGroups) > 1) {
            // 2+ excluded groups is ScoreSealedRmibAnswerSet's
            // `SEALED_RMIB_RESULT_NOT_SCORABLE` signal (P3) -- a
            // SealedRmibResult should never be sealed for that case.
            throw self::invalid();
        }

        $seen = [];
        foreach ($excludedGroups as $group) {
            if (! is_int($group) || $group < 1 || $group > 9 || isset($seen[$group])) {
                throw self::invalid();
            }
            $seen[$group] = true;
        }

        if ($reviewRequired !== ($excludedGroups !== [])) {
            throw self::invalid();
        }
    }

    private static function validDegenerateBand(mixed $band, int $rank): bool
    {
        return is_array($band)
            && array_keys($band) === ['lo', 'hi']
            && $band['lo'] === $rank
            && $band['hi'] === $rank;
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
        return new UnexpectedValueException('SEALED_RMIB_RESULT_INVALID');
    }
}
