<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class SealedGenericAnswerSet
{
    /**
     * @param  array<mixed>  $answers
     */
    private function __construct(
        public int $assessmentCaseId,
        public int $sessionId,
        public int $participantId,
        public string $sessionPublicId,
        public GenericAssessmentInstrument $instrument,
        public int $attemptNo,
        public string $submittedAt,
        public int $answersRevision,
        public SessionDefinition $definition,
        public array $answers,
        public string $sourceChecksum,
        private string $canonicalJson,
    ) {}

    /**
     * @param  array<mixed>  $answers
     */
    public static function seal(
        int $assessmentCaseId,
        int $sessionId,
        int $participantId,
        string $sessionPublicId,
        GenericAssessmentInstrument $instrument,
        int $attemptNo,
        string $submittedAt,
        int $answersRevision,
        SessionDefinition $definition,
        array $answers,
    ): self {
        if ($assessmentCaseId < 1 || $sessionId < 1 || $participantId < 1 || $attemptNo < 1
            || $answersRevision < 1 || $sessionPublicId === '' || $submittedAt === '') {
            throw self::invalid();
        }

        $canonicalAnswers = self::canonicalAnswers($answers);
        $payload = self::canonicalize([
            'assessmentCaseId' => $assessmentCaseId,
            'sessionId' => $sessionId,
            'participantId' => $participantId,
            'sessionPublicId' => $sessionPublicId,
            'instrument' => $instrument->value,
            'attemptNo' => $attemptNo,
            'submittedAt' => $submittedAt,
            'answersRevision' => $answersRevision,
            'definition' => $definition->toArray(),
            'answers' => $canonicalAnswers,
        ]);
        $payloadJson = self::encode($payload);
        $sourceChecksum = hash('sha256', 'sealed-generic-answers:v1|'.$payloadJson);
        $envelopeJson = self::encode(self::canonicalize([
            ...$payload,
            'sourceChecksum' => $sourceChecksum,
        ]));

        return new self(
            assessmentCaseId: $assessmentCaseId,
            sessionId: $sessionId,
            participantId: $participantId,
            sessionPublicId: $sessionPublicId,
            instrument: $instrument,
            attemptNo: $attemptNo,
            submittedAt: $submittedAt,
            answersRevision: $answersRevision,
            definition: $definition,
            answers: $canonicalAnswers,
            sourceChecksum: $sourceChecksum,
            canonicalJson: $envelopeJson,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assessmentCaseId' => $this->assessmentCaseId,
            'sessionId' => $this->sessionId,
            'participantId' => $this->participantId,
            'sessionPublicId' => $this->sessionPublicId,
            'instrument' => $this->instrument->value,
            'attemptNo' => $this->attemptNo,
            'submittedAt' => $this->submittedAt,
            'answersRevision' => $this->answersRevision,
            'definition' => $this->definition->toArray(),
            'answers' => $this->answers,
            'sourceChecksum' => $this->sourceChecksum,
        ];
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    /**
     * @param  array<mixed>  $answers
     * @return list<array{item_no:int,value:mixed,revision:int,answered_at:string}>
     */
    private static function canonicalAnswers(array $answers): array
    {
        if (! array_is_list($answers) || $answers === []) {
            throw self::invalid();
        }

        $canonical = [];
        foreach ($answers as $answer) {
            if (array_keys($answer) !== ['item_no', 'value', 'revision', 'answered_at']
                || ! is_int($answer['item_no']) || $answer['item_no'] < 1
                || ! is_int($answer['revision']) || $answer['revision'] < 1
                || ! is_string($answer['answered_at']) || $answer['answered_at'] === '') {
                throw self::invalid();
            }
            $canonical[] = [
                'item_no' => $answer['item_no'],
                'value' => self::canonicalize($answer['value']),
                'revision' => $answer['revision'],
                'answered_at' => $answer['answered_at'],
            ];
        }

        return $canonical;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $canonical = new stdClass;
            foreach ($properties as $key => $entry) {
                $canonical->{$key} = self::canonicalize($entry);
            }

            return $canonical;
        }
        if (! is_array($value)) {
            if (is_float($value) && ! is_finite($value)) {
                throw self::invalid();
            }
            if (! is_null($value) && ! is_bool($value) && ! is_int($value)
                && ! is_float($value) && ! is_string($value)) {
                throw self::invalid();
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw self::invalid();
            }
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
        return new UnexpectedValueException('SEALED_GENERIC_ANSWER_INVALID');
    }
}
