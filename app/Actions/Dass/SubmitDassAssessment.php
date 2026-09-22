<?php

declare(strict_types=1);

namespace App\Actions\Dass;

use App\Domain\Dass\DassAssessmentSnapshot;
use App\Domain\Dass\DassAssessmentStatus;
use App\Domain\Dass\InvalidDassAssessmentState;
use App\Security\RlsContextRunner;
use App\Services\Dass\Dass21ConfigReader;
use App\Services\Dass\DassTableNames;
use App\Services\Scoring\Dass21Scorer;
use App\Services\Scoring\Dass21ScreeningPolicy;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Submit-once DASS-21 scoring (Lead's decision (a), 2026-09-22): all 21
 * responses arrive together, are validated (`SubmitDassAssessmentRequest`
 * at the HTTP boundary, `Dass21Scorer` again here as the domain authority),
 * scored, evaluated, and persisted in one transaction -- no autosave, no
 * partial state, no revision conflicts to resolve.
 *
 * `dass.results` writes are service-only by the existing RLS
 * (`database/schema/rls_policies.sql`'s `dass_results_write` policy,
 * unchanged, see `tasks/handoffs/f2/dass21-participant-flow-investigation.md`'s
 * correction) -- this is why the WHOLE action runs as one service
 * transaction rather than splitting `assessments`/`responses` writes into
 * a plain participant RLS context: `RlsContextRunner::runAsService()`
 * explicitly refuses to elevate a participant context mid-request, so an
 * action that ever needs the results write cannot also run any other part
 * of itself as a raw participant-role connection.
 *
 * The participant-facing return value is `DassAssessmentSnapshot`
 * (status/timestamps only) -- never score/category content. Building the
 * actual result display is explicitly deferred (Lead: project-owner
 * decision (c) on whether even the general category should reach the
 * participant live is still pending); `GetDassResultSummary` exists and is
 * tested, but has no HTTP route yet, so there is nothing for a participant
 * to call that would leak it today.
 */
final readonly class SubmitDassAssessment
{
    private Closure $clock;

    public function __construct(
        private RlsContextRunner $contexts,
        private Dass21ConfigReader $config,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /** @param  list<array{item: int, score: int}>  $responses */
    public function execute(int $participantId, string $publicId, array $responses): DassAssessmentSnapshot
    {
        $this->assertCleanOuterBoundary();

        return $this->contexts->runAsService(
            fn (): DassAssessmentSnapshot => $this->withinTransaction($participantId, $publicId, $responses),
        );
    }

    /** @param  list<array{item: int, score: int}>  $responses */
    private function withinTransaction(int $participantId, string $publicId, array $responses): DassAssessmentSnapshot
    {
        return DB::transaction(function () use ($participantId, $publicId, $responses): DassAssessmentSnapshot {
            if (! Str::isUlid($publicId)) {
                throw new InvalidDassAssessmentState('DASS-21 assessment not found.');
            }

            $assessment = DB::table(DassTableNames::assessments())
                ->where('public_id', $publicId)
                ->where('participant_id', $participantId)
                ->lockForUpdate()
                ->first();

            if ($assessment === null) {
                throw new InvalidDassAssessmentState('DASS-21 assessment not found.');
            }

            // Idempotent replay: submitting again against an already-completed
            // assessment returns its existing snapshot rather than re-scoring
            // (re-scoring would violate dass.results' unique(assessment_id) and,
            // more importantly, silently overwrite a psychologist-reviewed
            // result with a later duplicate request's payload).
            if ((string) $assessment->status === DassAssessmentStatus::Completed->value) {
                return new DassAssessmentSnapshot(
                    (string) $assessment->public_id,
                    DassAssessmentStatus::Completed,
                    $this->storedTimestamp($assessment->started_at),
                    $this->storedTimestamp($assessment->completed_at),
                );
            }

            if ((string) $assessment->status !== DassAssessmentStatus::InProgress->value) {
                throw new InvalidDassAssessmentState('DASS-21 assessment is not in progress.');
            }

            $config = $this->config->read();
            $scorer = new Dass21Scorer($config['items'], $config['cutoffs'], $config['multiplier']);

            try {
                $scored = $scorer->score($responses);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidDassAssessmentState('DASS-21 responses failed domain validation: '.$exception->getMessage(), previous: $exception);
            }

            $now = ($this->clock)();
            if (! $now instanceof DateTimeImmutable) {
                throw new LogicException('The DASS-21 submit clock must return DateTimeImmutable.');
            }

            $startedAt = $assessment->started_at === null
                ? throw new LogicException('An in-progress DASS-21 assessment requires started_at.')
                : new DateTimeImmutable((string) $assessment->started_at);
            $completionDurationSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());

            $policy = new Dass21ScreeningPolicy($config['minimumCompletionSeconds']);
            $screening = $policy->evaluate($scored, $completionDurationSeconds);

            $responseRows = [];
            foreach ($responses as $response) {
                $responseRows[] = [
                    'assessment_id' => $assessment->id,
                    'item_number' => $response['item'],
                    'response_value' => $response['score'],
                    'response_time_ms' => null,
                    'answered_at' => $this->timestamp($now),
                    'expires_at' => $this->timestamp($now->modify('+2 years')),
                    'created_at' => $this->timestamp($now),
                    'updated_at' => $this->timestamp($now),
                ];
            }
            DB::table(DassTableNames::responses())->insert($responseRows);

            DB::table(DassTableNames::results())->insert([
                'assessment_id' => $assessment->id,
                'depression_raw' => $scored['subscales']['D']['raw_score'],
                'anxiety_raw' => $scored['subscales']['A']['raw_score'],
                'stress_raw' => $scored['subscales']['S']['raw_score'],
                'depression_score' => $scored['subscales']['D']['score_x2'],
                'anxiety_score' => $scored['subscales']['A']['score_x2'],
                'stress_score' => $scored['subscales']['S']['score_x2'],
                'depression_category' => $scored['subscales']['D']['category'],
                'anxiety_category' => $scored['subscales']['A']['category'],
                'stress_category' => $scored['subscales']['S']['category'],
                'overall_category' => $screening['general']['category'],
                'follow_up' => $screening['follow_up']['type'],
                'validity_flags' => json_encode([
                    'flags' => $screening['validity_flags'],
                    'active_flag_codes' => $screening['active_flag_codes'],
                ], JSON_THROW_ON_ERROR),
                'expires_at' => $this->timestamp($now->modify('+2 years')),
                'created_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
            ]);

            $updated = DB::table(DassTableNames::assessments())
                ->where('id', $assessment->id)
                ->where('status', DassAssessmentStatus::InProgress->value)
                ->update([
                    'status' => DassAssessmentStatus::Completed->value,
                    'completed_at' => $this->timestamp($now),
                    'updated_at' => $this->timestamp($now),
                ]);
            if ($updated !== 1) {
                throw new LogicException('The DASS-21 assessment completion could not be committed.');
            }

            return new DassAssessmentSnapshot(
                (string) $assessment->public_id,
                DassAssessmentStatus::Completed,
                $this->rfc3339($startedAt),
                $this->rfc3339($now),
            );
        });
    }

    private function assertCleanOuterBoundary(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Submitting a DASS-21 assessment must own its outer service transaction.');
        }
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    private function rfc3339(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.up');
    }

    private function storedTimestamp(mixed $value): ?string
    {
        return $value === null ? null : $this->rfc3339(new DateTimeImmutable((string) $value));
    }
}
