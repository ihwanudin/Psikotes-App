<?php

declare(strict_types=1);

namespace App\Domain\Dass;

/**
 * The participant-facing DASS-21 assessment state — status and timestamps
 * only, never score/category/follow-up content. Lead's explicit 2026-09-22
 * instruction: don't build result display to the participant yet (decision
 * (c), still pending project-owner sign-off per
 * `tasks/handoffs/f2/dass21-participant-flow-investigation.md`) — this DTO
 * structurally cannot carry result content, so a future caller can't
 * accidentally leak it here even before that endpoint exists.
 */
final readonly class DassAssessmentSnapshot
{
    public function __construct(
        public string $publicId,
        public DassAssessmentStatus $status,
        public ?string $startedAt,
        public ?string $completedAt,
    ) {}
}
