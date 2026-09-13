<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\PersistedIstResult;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use UnexpectedValueException;

final readonly class LoadPersistedIstResult
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(string $resultPublicId): PersistedIstResult
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('IST_RESULT_READ_CONTEXT_REQUIRED');
        }
        if (strtoupper($resultPublicId) !== $resultPublicId || ! Str::isUlid($resultPublicId)) {
            throw self::invalid();
        }

        try {
            $parent = DB::table('generic_instrument_results')
                ->where('public_id', $resultPublicId)
                ->where('instrument_code', 'ist')
                ->first([
                    'id', 'public_id', 'assessment_case_id', 'session_id', 'participant_id',
                    'session_public_id', 'instrument_code', 'attempt_no', 'submitted_at',
                    'answers_revision', 'sealed_source_checksum', 'session_definition_version',
                    'session_definition_provenance', 'session_definition_checksum',
                    'session_definition_payload', 'instrument_version_id', 'instrument_version',
                    'instrument_source_file', 'instrument_checksum', 'result_contract_version',
                    'result_payload', 'result_checksum', 'created_at',
                ]);
            if ($parent === null) {
                throw self::invalid();
            }

            $sources = array_values(DB::table('generic_instrument_result_sources')
                ->where('result_id', $parent->id)
                ->orderBy('ordinal')
                ->get([
                    'id', 'result_id', 'ordinal', 'source_code', 'raw_score', 'standard_score',
                    'source_score', 'level', 'category', 'band_low', 'band_high', 'created_at',
                ])->map(static fn (object $row): array => (array) $row)->all());

            return PersistedIstResult::hydrate((array) $parent, $sources);
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() === 'IST_RESULT_READ_INVALID') {
                throw $exception;
            }

            throw self::invalid();
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('IST_RESULT_READ_INVALID');
    }
}
