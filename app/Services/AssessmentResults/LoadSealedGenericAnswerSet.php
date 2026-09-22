<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use stdClass;
use Throwable;
use UnexpectedValueException;

final readonly class LoadSealedGenericAnswerSet
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(int $sessionId): SealedGenericAnswerSet
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('SEALED_GENERIC_ANSWER_CONTEXT_REQUIRED');
        }
        if ($sessionId < 1) {
            throw self::invalid();
        }

        $session = DB::table('test_sessions as session')
            ->join('assessment_cases as assessment_case', 'assessment_case.id', '=', 'session.assessment_case_id')
            ->where('session.id', $sessionId)
            ->select([
                'session.id',
                'session.public_id',
                'session.participant_id',
                'session.assessment_case_id',
                'session.test_type',
                'session.attempt_no',
                'session.duration_seconds',
                'session.status',
                'session.answers_revision',
                'session.submitted_at',
                'session.session_definition_version',
                'session.session_definition_provenance',
                'session.session_definition_checksum',
                'session.session_definition_payload',
                'assessment_case.participant_id as case_participant_id',
            ])
            ->lockForUpdate()
            ->first();
        if ($session === null) {
            throw self::invalid();
        }

        $instrument = $this->instrument($session->test_type ?? null);
        if (! in_array($instrument, [
            GenericAssessmentInstrument::Ist,
            GenericAssessmentInstrument::Papi,
            GenericAssessmentInstrument::Rmib,
        ], true)) {
            throw new UnexpectedValueException('SEALED_GENERIC_ANSWER_INSTRUMENT_UNSUPPORTED');
        }

        if (! in_array($session->status ?? null, ['submitted', 'scored'], true)
            || (int) ($session->id ?? 0) !== $sessionId
            || (int) ($session->participant_id ?? 0) < 1
            || (int) ($session->assessment_case_id ?? 0) < 1
            || (int) ($session->case_participant_id ?? 0) !== (int) $session->participant_id
            || (int) ($session->attempt_no ?? 0) < 1
            || (int) ($session->answers_revision ?? 0) < 1
            || ! is_string($session->public_id ?? null)
            || ! Str::isUlid((string) $session->public_id)) {
            throw self::invalid();
        }

        $definition = $this->definition($session, $instrument);
        $expectedItems = array_sum(array_column($definition->subtests, 'item_count'));
        $answerRows = DB::table('answers')
            ->where('session_id', $sessionId)
            ->orderBy('item_no')
            ->get(['item_no', 'value', 'revision', 'answered_at']);
        if ($answerRows->count() !== $expectedItems) {
            throw self::invalid();
        }

        $answers = [];
        foreach ($answerRows as $offset => $answer) {
            $itemNo = (int) ($answer->item_no ?? 0);
            $revision = (int) ($answer->revision ?? 0);
            if ($itemNo !== $offset + 1
                || $revision < 1
                || $revision > (int) $session->answers_revision
                || ! is_string($answer->value ?? null)) {
                throw self::invalid();
            }
            $answers[] = [
                'item_no' => $itemNo,
                'value' => $this->decodeAnswer((string) $answer->value),
                'revision' => $revision,
                'answered_at' => $this->timestamp($answer->answered_at ?? null),
            ];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: (int) $session->assessment_case_id,
            sessionId: $sessionId,
            participantId: (int) $session->participant_id,
            sessionPublicId: strtoupper((string) $session->public_id),
            instrument: $instrument,
            attemptNo: (int) $session->attempt_no,
            submittedAt: $this->timestamp($session->submitted_at ?? null),
            answersRevision: (int) $session->answers_revision,
            definition: $definition,
            answers: $answers,
        );
    }

    private function instrument(mixed $value): GenericAssessmentInstrument
    {
        if (! is_string($value)) {
            throw self::invalid();
        }

        try {
            return GenericAssessmentInstrument::fromExternal($value);
        } catch (Throwable) {
            throw new UnexpectedValueException('SEALED_GENERIC_ANSWER_INSTRUMENT_UNSUPPORTED');
        }
    }

    private function definition(object $session, GenericAssessmentInstrument $instrument): SessionDefinition
    {
        if (! is_string($session->session_definition_payload ?? null)
            || ! is_string($session->session_definition_version ?? null)
            || ! is_string($session->session_definition_provenance ?? null)
            || ! is_string($session->session_definition_checksum ?? null)) {
            throw self::invalid();
        }

        try {
            $payload = json_decode(
                $session->session_definition_payload,
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
            if (! is_array($payload)) {
                throw self::invalid();
            }
            $definition = SessionDefinition::fromArray($payload);
        } catch (UnexpectedValueException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::invalid();
        }

        // F2 timed-segments stage 3 (2026-09-22): test_sessions.duration_seconds
        // stores totalDurationSeconds + totalReadingCapSeconds (revision 1's
        // ends_at formula), not totalDurationSeconds alone -- see
        // AllocateAndStartAssessmentSession::sessionDurationSeconds(). Always 0
        // reading cap today, so this is currently a no-op change.
        if ($definition->instrument !== $instrument
            || $definition->version !== $session->session_definition_version
            || $definition->provenance !== $session->session_definition_provenance
            || $definition->checksum !== $session->session_definition_checksum
            || $definition->totalDurationSeconds + $definition->totalReadingCapSeconds !== (int) ($session->duration_seconds ?? 0)) {
            throw self::invalid();
        }

        return $definition;
    }

    private function decodeAnswer(string $encoded): mixed
    {
        try {
            $preserved = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $native = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
            if (! $this->sameJsonTypes($preserved, $native)) {
                throw self::invalid();
            }

            return $preserved;
        } catch (JsonException) {
            throw self::invalid();
        }
    }

    private function sameJsonTypes(mixed $preserved, mixed $native): bool
    {
        if ($preserved instanceof stdClass) {
            if (! $native instanceof stdClass) {
                return false;
            }
            $preservedProperties = get_object_vars($preserved);
            $nativeProperties = get_object_vars($native);
            if (array_keys($preservedProperties) !== array_keys($nativeProperties)) {
                return false;
            }
            foreach ($preservedProperties as $key => $value) {
                if (! $this->sameJsonTypes($value, $nativeProperties[$key])) {
                    return false;
                }
            }

            return true;
        }
        if ($native instanceof stdClass) {
            return false;
        }
        if (is_array($preserved)) {
            if (! is_array($native)) {
                return false;
            }
            if (array_keys($preserved) !== array_keys($native)) {
                return false;
            }
            foreach ($preserved as $key => $value) {
                if (! $this->sameJsonTypes($value, $native[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($native)) {
            return false;
        }

        return get_debug_type($preserved) === get_debug_type($native);
    }

    private function timestamp(mixed $value): string
    {
        if (! is_string($value) || preg_match(
            '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)$/D',
            $value,
        ) !== 1) {
            throw self::invalid();
        }

        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw self::invalid();
            }

            return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (UnexpectedValueException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('SEALED_GENERIC_ANSWER_INVALID');
    }
}
