<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\AssessmentSessions\AssessmentSessionAllocationResult;
use App\Actions\AssessmentSessions\AssessmentSessionSnapshot;
use App\Domain\AssessmentSessions\TimedSegmentSweep;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * F2 S5 (2026-09-21), widened F2 session-http (2026-09-21). The
 * `AssessmentSession` shape from API_CONTRACT.md §98, plus the `replayed`
 * boolean the start endpoint's contract adds on top of it.
 *
 * Wraps either AssessmentSessionAllocationResult (S5's start/replay result,
 * always InProgress by that class's own constructor invariant) or
 * AssessmentSessionSnapshot (session-http's GET /sessions/{id} read, any of
 * the six states) -- deliberately a union, not one shared type.
 * AssessmentSessionAllocationResult's strict constructor (ULID shape,
 * endsAt matching the definition's duration exactly, serverTime inside the
 * active window) is specifically appropriate for a just-allocated/
 * just-replayed result and stays untouched; AssessmentSessionSnapshot
 * carries no such invariants because a GET must be able to represent a
 * closed session just as well as an open one. The two differ only in
 * `submitted_at` (the allocation result is always InProgress, so it has none)
 * and in whether `replayed` is meaningful (GET has no such concept -- always
 * reported false, since resuming an already-open session isn't "replaying"
 * an allocation).
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
 * @property-read AssessmentSessionAllocationResult|AssessmentSessionSnapshot $resource
 */
final class AssessmentSessionResource extends JsonResource
{
    public function __construct(AssessmentSessionAllocationResult|AssessmentSessionSnapshot $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;
        $definition = $resource->definition;

        return [
            'session_id' => $resource->sessionId,
            'test_type' => $resource->instrument->value,
            'status' => $resource->status->value,
            'attempt_no' => $resource->attemptNo,
            'started_at' => $this->rfc3339($resource->startedAt),
            'ends_at' => $this->rfc3339($resource->endsAt),
            'write_deadline' => $this->rfc3339($resource->endsAt),
            'submitted_at' => $resource instanceof AssessmentSessionSnapshot
                ? $this->rfc3339($resource->submittedAt)
                : null,
            'server_time' => $this->rfc3339($resource->serverTime),
            'remaining_seconds' => $resource->remainingSeconds,
            'answers_revision' => $resource->answersRevision,
            'config' => [
                'total_duration_seconds' => $definition->totalDurationSeconds,
                'subtests' => $definition->subtests,
                'randomization' => $definition->randomization,
                'generator' => $definition->generator,
            ],
            'seed' => $definition->seed,
            'replayed' => $resource instanceof AssessmentSessionAllocationResult && $resource->replayed,
            'current_segment' => $this->currentSegment($resource),
        ];
    }

    /**
     * F2 timed-segments stage 5 (2026-09-22). null only when the session has
     * never started (startedAt null -- 'created', or 'void' straight from
     * 'created'); every other status necessarily entered segment 0 at least
     * once. Computed fresh via TimedSegmentSweep on every read -- compute-
     * only, same as remaining_seconds, never persisted here (see
     * TimedSegmentSweep's own docblock for why). For
     * AssessmentSessionAllocationResult (just started/replayed), there is
     * never a stored index yet, so the sweep seeds from startedAt exactly
     * the way a session that has never subtest/next'd already does.
     *
     * @return array{code: string, index: int, started_at: string|null, ends_at: string|null, remaining_seconds: int|null}|null
     */
    private function currentSegment(AssessmentSessionAllocationResult|AssessmentSessionSnapshot $resource): ?array
    {
        if ($resource->startedAt === null) {
            return null;
        }

        $swept = (new TimedSegmentSweep)->evaluate(
            $resource->definition->segments,
            $resource instanceof AssessmentSessionSnapshot ? $resource->currentSegmentIndex : null,
            $resource instanceof AssessmentSessionSnapshot ? $resource->currentSegmentBecameCurrentAt : null,
            $resource instanceof AssessmentSessionSnapshot ? $resource->currentSegmentStartedAt : null,
            $resource->startedAt,
            $resource->serverTime,
        );

        $segment = $resource->definition->segments[$swept->index];
        $endsAt = $swept->startedAt?->modify("+{$segment->durationSeconds} seconds");
        $remainingSeconds = $endsAt === null ? null : max(0, (int) ceil(
            (float) $endsAt->format('U.u') - (float) $resource->serverTime->format('U.u'),
        ));

        return [
            'code' => $segment->code,
            'index' => $swept->index,
            'started_at' => $this->rfc3339($swept->startedAt),
            'ends_at' => $this->rfc3339($endsAt),
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    private function rfc3339(?DateTimeImmutable $time): ?string
    {
        return $time?->format('Y-m-d\TH:i:s.up');
    }
}
