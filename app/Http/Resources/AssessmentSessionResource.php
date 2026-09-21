<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\AssessmentSessions\AssessmentSessionAllocationResult;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * F2 S5 (2026-09-21). The `AssessmentSession` shape from API_CONTRACT.md §98, plus
 * the `replayed` boolean the start endpoint's contract adds on top of it. No prior
 * implementation of this shape exists anywhere in the codebase to reuse (GET
 * /sessions/{id} is not built yet); this is the first, meant to be reused there too.
 *
 * `config`/`seed` split: API_CONTRACT.md names both fields but does not define
 * config's exact shape beyond "the client needs it to render the test". This
 * resource takes the reading that `config` is the client-facing rendering shape
 * (subtests, total duration, randomization mode, generator parameters) and `seed`
 * is kept as its own top-level field rather than folded into config, matching how
 * the contract calls it out separately. version/provenance/checksum are left out of
 * config: they are integrity/audit metadata for the server and for replay
 * verification, not something a rendering client consumes.
 *
 * @property-read AssessmentSessionAllocationResult $resource
 */
final class AssessmentSessionResource extends JsonResource
{
    public function __construct(AssessmentSessionAllocationResult $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $definition = $this->resource->definition;

        return [
            'session_id' => $this->resource->sessionId,
            'test_type' => $this->resource->instrument->value,
            'status' => $this->resource->status->value,
            'attempt_no' => $this->resource->attemptNo,
            'started_at' => $this->rfc3339($this->resource->startedAt),
            'ends_at' => $this->rfc3339($this->resource->endsAt),
            'write_deadline' => $this->rfc3339($this->resource->writeDeadline),
            'submitted_at' => null,
            'server_time' => $this->rfc3339($this->resource->serverTime),
            'remaining_seconds' => $this->resource->remainingSeconds,
            'answers_revision' => $this->resource->answersRevision,
            'config' => [
                'total_duration_seconds' => $definition->totalDurationSeconds,
                'subtests' => $definition->subtests,
                'randomization' => $definition->randomization,
                'generator' => $definition->generator,
            ],
            'seed' => $definition->seed,
            'replayed' => $this->resource->replayed,
        ];
    }

    private function rfc3339(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.up');
    }
}
