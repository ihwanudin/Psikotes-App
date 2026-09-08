<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentAutosaveMutation;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionErrorCode;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentAutosavePolicyTest extends TestCase
{
    public function test_new_mutation_accepts_only_the_next_revision(): void
    {
        $decision = $this->decide(currentRevision: 6, proposedRevision: 7);

        $this->assertTrue($decision->accepted);
        $this->assertTrue($decision->shouldPersist);
        $this->assertSame(7, $decision->receipt?->revision);
        $this->assertSame([12, 13], $decision->receipt?->acceptedItemNumbers);
        $this->assertSame('2026-09-08T03:04:00+00:00', $decision->receipt?->receivedAt->format('c'));
        $this->assertNull($decision->errorCode);
    }

    public function test_identical_replay_returns_the_stored_receipt_without_a_write(): void
    {
        $first = $this->decide(currentRevision: 6, proposedRevision: 7);
        $this->assertNotNull($first->canonicalHash);
        $this->assertNotNull($first->receipt);
        $existing = new AssessmentAutosaveMutation($first->canonicalHash, $first->receipt);

        $replay = $this->decide(
            currentRevision: 7,
            proposedRevision: 7,
            items: [
                ['item_no' => 13, 'value' => ['rank' => 3]],
                ['item_no' => 12, 'value' => 'A'],
            ],
            existing: $existing,
            receivedAt: new DateTimeImmutable('2026-09-08T03:04:30.000000Z'),
        );

        $this->assertTrue($replay->accepted);
        $this->assertFalse($replay->shouldPersist);
        $this->assertSame($first->receipt, $replay->receipt);
        $this->assertSame(AssessmentSessionStatus::InProgress, $replay->status);
    }

    public function test_same_mutation_with_different_canonical_payload_is_rejected(): void
    {
        $first = $this->decide(currentRevision: 6, proposedRevision: 7);
        $existing = new AssessmentAutosaveMutation($first->canonicalHash ?? '', $first->receipt);

        $decision = $this->decide(
            currentRevision: 7,
            proposedRevision: 7,
            items: [['item_no' => 12, 'value' => 'changed']],
            existing: $existing,
        );

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame(AssessmentSessionErrorCode::MutationPayloadMismatch, $decision->errorCode);
    }

    public function test_same_mutation_with_a_different_revision_is_a_payload_mismatch(): void
    {
        $first = $this->decide(currentRevision: 6, proposedRevision: 7);
        $existing = new AssessmentAutosaveMutation($first->canonicalHash ?? '', $first->receipt);

        $decision = $this->decide(
            currentRevision: 7,
            proposedRevision: 8,
            existing: $existing,
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::MutationPayloadMismatch, $decision->errorCode);
    }

    public function test_a_different_mutation_at_a_consumed_revision_is_stale(): void
    {
        $decision = $this->decide(currentRevision: 7, proposedRevision: 7);

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::AutosaveStaleRevision, $decision->errorCode);
    }

    public function test_a_revision_that_skips_the_next_revision_is_a_gap(): void
    {
        $decision = $this->decide(currentRevision: 7, proposedRevision: 9);

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::AutosaveRevisionGap, $decision->errorCode);
    }

    public function test_empty_batch_is_rejected_fail_closed(): void
    {
        $decision = $this->decide(currentRevision: 0, proposedRevision: 1, items: []);

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::InvalidAnswerBatch, $decision->errorCode);
    }

    public function test_duplicate_item_number_is_rejected_fail_closed(): void
    {
        $decision = $this->decide(
            currentRevision: 0,
            proposedRevision: 1,
            items: [
                ['item_no' => 12, 'value' => 'A'],
                ['item_no' => 12, 'value' => 'B'],
            ],
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::InvalidAnswerBatch, $decision->errorCode);
    }

    /** @param array<int, mixed> $items */
    #[DataProvider('malformedBatches')]
    public function test_malformed_item_shapes_and_values_are_rejected_without_uncontrolled_errors(array $items): void
    {
        $decision = $this->decide(currentRevision: 0, proposedRevision: 1, items: $items);

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame(AssessmentSessionErrorCode::InvalidAnswerBatch, $decision->errorCode);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function malformedBatches(): iterable
    {
        yield 'item is not an array' => [['not-an-item']];
        yield 'missing item number' => [[['value' => 'A']]];
        yield 'missing value' => [[['item_no' => 1]]];
        yield 'string item number' => [[['item_no' => '1', 'value' => 'A']]];
        yield 'boolean item number' => [[['item_no' => true, 'value' => 'A']]];
        yield 'non-positive item number' => [[['item_no' => 0, 'value' => 'A']]];
        yield 'extra key' => [[['item_no' => 1, 'value' => 'A', 'client_time' => 123]]];
        yield 'nested NAN' => [[['item_no' => 1, 'value' => ['nested' => ['score' => NAN]]]]];
        yield 'nested infinity' => [[['item_no' => 1, 'value' => ['nested' => [INF]]]]];
    }

    public function test_new_mutation_after_deadline_is_rejected_and_expires_session(): void
    {
        $decision = $this->decide(
            currentRevision: 0,
            proposedRevision: 1,
            receivedAt: new DateTimeImmutable('2026-09-08T03:05:00.000001Z'),
        );

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame(AssessmentSessionStatus::Expired, $decision->status);
        $this->assertSame(AssessmentSessionErrorCode::DeadlineExceeded, $decision->errorCode);
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function decide(
        int $currentRevision,
        int $proposedRevision,
        array $items = [
            ['item_no' => 12, 'value' => 'A'],
            ['item_no' => 13, 'value' => ['rank' => 3]],
        ],
        ?AssessmentAutosaveMutation $existing = null,
        ?DateTimeImmutable $receivedAt = null,
    ): object {
        return (new AssessmentAutosavePolicy)->decide(
            AssessmentSessionStatus::InProgress,
            new DateTimeImmutable('2026-09-08T03:05:00.000000Z'),
            $receivedAt ?? new DateTimeImmutable('2026-09-08T03:04:00.000000Z'),
            '01J75D9KJ6M8K2Q4V7X1N3P5RS',
            $currentRevision,
            '01J75D9KJ6M8K2Q4V7X1N3P5RT',
            $proposedRevision,
            $items,
            $existing,
        );
    }
}
