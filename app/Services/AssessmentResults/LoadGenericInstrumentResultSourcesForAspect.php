<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\AspectSourceReading;
use App\Domain\AssessmentResults\AspectSourceReadingStatus;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20). Read-only entry point for the Eligibility lane's
 * G7 aggregator (app/Domain/Eligibility/AspectSourceDiscrepancyPolicy.php,
 * out of this lane's path ownership — Lead-confirmed 2026-09-20 this reader
 * is provided instead of wiring that policy directly). It cross-checks one
 * aspect's configured sources
 * (database/seeders/data/aspect_sources.json, read the same versioned way as
 * every other instrument table, via `instrument_versions` and `source_text`
 * — never a bare file read, and never `payload`, for the same reason
 * documented in ScoreSealedIstAnswerSet) against what is actually persisted
 * in `generic_instrument_results` / `generic_instrument_result_sources` for
 * one assessment case. It never writes, and it never picks a "winner" among
 * ambiguous results — see AspectSourceReadingStatus.
 *
 * See tasks/handoffs/f2/generic-instrument-result-field-mapping.md for what
 * every returned field means, and why `standardScore`, `sourceScore`,
 * `rawScore`, `category`, and `band` are deliberately NOT exposed here.
 */
final readonly class LoadGenericInstrumentResultSourcesForAspect
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    private const INSTRUMENT_PREFIXES = ['ist', 'papi', 'rmib', 'kraepelin'];

    public function __construct(private RlsContextRunner $contexts) {}

    /** @return list<AspectSourceReading> */
    public function execute(int $assessmentCaseId, string $aspect): array
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('ASPECT_SOURCE_READING_CONTEXT_REQUIRED');
        }
        if ($assessmentCaseId < 1 || ! in_array($aspect, self::ASPECTS, true)) {
            throw self::invalid();
        }

        try {
            $configuredSources = $this->configuredSources($aspect);

            return array_map(
                fn (string $configuredSource): AspectSourceReading => $this->reading(
                    $assessmentCaseId,
                    $aspect,
                    $configuredSource,
                ),
                $configuredSources,
            );
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() === 'ASPECT_SOURCE_READING_INVALID') {
                throw $exception;
            }

            throw self::invalid();
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    private function reading(int $assessmentCaseId, string $aspect, string $configuredSource): AspectSourceReading
    {
        [$instrumentCode, $sourceCode] = $this->splitConfiguredSource($configuredSource);

        $resultIds = DB::table('generic_instrument_results')
            ->where('assessment_case_id', $assessmentCaseId)
            ->where('instrument_code', $instrumentCode)
            ->pluck('id');

        if ($resultIds->count() === 0) {
            return new AspectSourceReading($aspect, $configuredSource, $instrumentCode, $sourceCode, AspectSourceReadingStatus::NotFound, null);
        }
        if ($resultIds->count() > 1) {
            return new AspectSourceReading($aspect, $configuredSource, $instrumentCode, $sourceCode, AspectSourceReadingStatus::Ambiguous, null);
        }

        $level = DB::table('generic_instrument_result_sources')
            ->where('result_id', $resultIds->first())
            ->where('source_code', $sourceCode)
            ->value('level');

        if ($level === null) {
            return new AspectSourceReading($aspect, $configuredSource, $instrumentCode, $sourceCode, AspectSourceReadingStatus::SourceMissingFromResult, null);
        }

        $level = (int) $level;
        if ($level < 1 || $level > 5) {
            throw self::invalid();
        }

        return new AspectSourceReading($aspect, $configuredSource, $instrumentCode, $sourceCode, AspectSourceReadingStatus::Found, $level);
    }

    /** @return array{0:string,1:string} */
    private function splitConfiguredSource(string $configuredSource): array
    {
        foreach (self::INSTRUMENT_PREFIXES as $prefix) {
            $needle = strtoupper($prefix).'_';
            if (str_starts_with($configuredSource, $needle)) {
                $sourceCode = substr($configuredSource, strlen($needle));
                if ($sourceCode === '') {
                    throw self::invalid();
                }

                return [$prefix, $sourceCode];
            }
        }

        throw self::invalid();
    }

    /** @return list<string> */
    private function configuredSources(string $aspect): array
    {
        $row = DB::table('instrument_versions')
            ->where('code', 'aspect_sources')
            ->where('is_active', true)
            ->select(['checksum', 'source_text'])
            ->first();
        // See ScoreSealedIstAnswerSet for why source_text, never payload, with
        // no fallback.
        $sourceText = $row->source_text ?? null;
        if ($row === null || ! is_string($row->checksum ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $row->checksum) !== 1
            || ! is_string($sourceText) || $sourceText === ''
            || ! hash_equals($row->checksum, hash('sha256', $sourceText))) {
            throw self::invalid();
        }

        try {
            $data = json_decode($sourceText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid();
        }
        if (! is_array($data) || array_keys($data) !== ['version', 'aspects']
            || ! is_array($data['aspects']) || ! isset($data['aspects'][$aspect])
            || ! is_array($data['aspects'][$aspect]) || ! array_is_list($data['aspects'][$aspect])
            || $data['aspects'][$aspect] === []) {
            throw self::invalid();
        }

        $sources = [];
        foreach ($data['aspects'][$aspect] as $source) {
            if (! is_string($source) || $source === '' || in_array($source, $sources, true)) {
                throw self::invalid();
            }
            $sources[] = $source;
        }

        return $sources;
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('ASPECT_SOURCE_READING_INVALID');
    }
}
