<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;
use JsonException;

final class AssessmentAutosavePolicy
{
    /**
     * @param  array<int, mixed>  $items
     */
    public function decide(
        AssessmentSessionStatus $status,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $receivedAt,
        string $sessionId,
        int $currentRevision,
        string $mutationId,
        int $proposedRevision,
        array $items,
        ?AssessmentAutosaveMutation $existingMutation,
    ): AssessmentAutosaveDecision {
        if ($currentRevision < 0) {
            throw new InvalidAssessmentSessionState('The accepted answer revision cannot be negative.');
        }

        $canonical = $this->canonicalize($sessionId, $proposedRevision, $items);
        if ($mutationId === '' || $proposedRevision < 1 || $canonical === null) {
            return $this->reject($status, AssessmentSessionErrorCode::InvalidAnswerBatch);
        }

        if ($existingMutation !== null) {
            if ($existingMutation->receipt->mutationId !== $mutationId) {
                throw new InvalidAssessmentSessionState('The loaded mutation does not match the requested identity.');
            }

            if (! hash_equals($existingMutation->canonicalHash, $canonical['hash'])) {
                return $this->reject($status, AssessmentSessionErrorCode::MutationPayloadMismatch);
            }

            return new AssessmentAutosaveDecision(
                true,
                false,
                $status,
                null,
                $existingMutation->receipt,
                $existingMutation->canonicalHash,
            );
        }

        $deadline = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite($status, $endsAt, $receivedAt);
        if (! $deadline->accepted) {
            return $this->reject($deadline->status, $deadline->errorCode);
        }

        if ($proposedRevision <= $currentRevision) {
            return $this->reject($status, AssessmentSessionErrorCode::AutosaveStaleRevision);
        }

        if ($proposedRevision !== $currentRevision + 1) {
            return $this->reject($status, AssessmentSessionErrorCode::AutosaveRevisionGap);
        }

        return new AssessmentAutosaveDecision(
            true,
            true,
            $status,
            null,
            new AssessmentAutosaveReceipt(
                $mutationId,
                $proposedRevision,
                $canonical['item_numbers'],
                $receivedAt,
            ),
            $canonical['hash'],
        );
    }

    private function reject(
        AssessmentSessionStatus $status,
        ?AssessmentSessionErrorCode $errorCode,
    ): AssessmentAutosaveDecision {
        if ($errorCode === null) {
            throw new InvalidAssessmentSessionState('A rejected autosave decision requires an error code.');
        }

        return new AssessmentAutosaveDecision(false, false, $status, $errorCode);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{hash: string, item_numbers: list<int>}|null
     */
    private function canonicalize(string $sessionId, int $revision, array $items): ?array
    {
        if ($sessionId === '' || $revision < 1 || $items === []) {
            return null;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || count($item) !== 2
                || ! array_key_exists('item_no', $item)
                || ! array_key_exists('value', $item)
                || ! is_int($item['item_no'])
                || $item['item_no'] < 1
                || array_key_exists($item['item_no'], $normalized)) {
                return null;
            }

            $normalized[$item['item_no']] = $this->normalizeValue($item['value']);
        }
        ksort($normalized, SORT_NUMERIC);

        try {
            $encoded = json_encode([
                'session_id' => $sessionId,
                'revision' => $revision,
                'items' => $normalized,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            return null;
        }

        return [
            'hash' => hash('sha256', $encoded),
            'item_numbers' => array_map(intval(...), array_keys($normalized)),
        ];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->normalizeValue($nested);
        }

        return $value;
    }
}
