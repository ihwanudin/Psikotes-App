<?php

declare(strict_types=1);

namespace App\Actions\AssessmentResults;

use App\Domain\AssessmentResults\AssessmentScoringOutcome;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\LoadSealedGenericAnswerSet;
use App\Services\AssessmentResults\PersistSealedIstResult;
use App\Services\AssessmentResults\PersistSealedPapiResult;
use App\Services\AssessmentResults\PersistSealedRmibResult;
use App\Services\AssessmentResults\ScoreSealedIstAnswerSet;
use App\Services\AssessmentResults\ScoreSealedPapiAnswerSet;
use App\Services\AssessmentResults\ScoreSealedRmibAnswerSet;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

/**
 * ADR-0032 (2026-09-22): the orchestrator the ADR's Context section found
 * missing -- nothing before this ever called
 * loader -> scorer -> persister for a real session. Callers MUST already be
 * inside the same service-role transaction as the status transition that
 * triggers scoring (submit, or ADR-0032 PR2's expired sweep) -- see this
 * class's own transaction guard below and ADR-0032 §1(a)/§3 for why: the
 * status write and the scoring outcome (result row OR failure row) must
 * commit or roll back together, and only a genuine infrastructure failure
 * may roll back either.
 *
 * `execute()` never throws for a PREDICTABLE scoring rejection -- today
 * that is only `LoadSealedGenericAnswerSet`'s `SEALED_GENERIC_ANSWER_INCOMPLETE`
 * (PAPI's all-or-nothing completeness policy, still enforced verbatim; see
 * that class). Any OTHER exception is a genuine bug or data-integrity
 * problem, not a participant leaving items blank, and is left to propagate
 * so the caller's transaction rolls back -- this class does not decide that
 * boundary is safe to swallow, only the one specific, coded, expected
 * rejection is.
 */
final class ScoreAssessmentSession
{
    private const SCORABLE = [
        GenericAssessmentInstrument::Ist,
        GenericAssessmentInstrument::Papi,
        GenericAssessmentInstrument::Rmib,
    ];

    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly LoadSealedGenericAnswerSet $loader,
        private readonly ScoreSealedIstAnswerSet $scoreIst,
        private readonly ScoreSealedPapiAnswerSet $scorePapi,
        private readonly ScoreSealedRmibAnswerSet $scoreRmib,
        private readonly PersistSealedIstResult $persistIst,
        private readonly PersistSealedPapiResult $persistPapi,
        private readonly PersistSealedRmibResult $persistRmib,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public static function scores(GenericAssessmentInstrument $instrument): bool
    {
        return in_array($instrument, self::SCORABLE, true);
    }

    public function execute(int $sessionId, GenericAssessmentInstrument $instrument): AssessmentScoringOutcome
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('ASSESSMENT_SCORING_CONTEXT_REQUIRED');
        }
        if (! self::scores($instrument)) {
            throw new LogicException('ASSESSMENT_SCORING_INSTRUMENT_UNSUPPORTED');
        }

        $attemptedAt = ($this->clock)();
        if (! $attemptedAt instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment scoring clock must return DateTimeImmutable.');
        }

        try {
            $sealed = $this->loader->execute($sessionId);
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() !== 'SEALED_GENERIC_ANSWER_INCOMPLETE') {
                throw $exception;
            }

            $outcome = AssessmentScoringOutcome::failedToScore('INCOMPLETE_ANSWERS');
            $this->recordAttempt($sessionId, $instrument, $attemptedAt, $outcome);

            return $outcome;
        }

        $instrumentVersionId = $this->activeInstrumentVersionId($instrument);

        $resultPublicId = match ($instrument) {
            GenericAssessmentInstrument::Ist => $this->persistIst->execute(
                $this->scoreIst->execute($sealed, $instrumentVersionId),
            ),
            GenericAssessmentInstrument::Papi => $this->persistPapi->execute(
                $this->scorePapi->execute($sealed, $instrumentVersionId),
            ),
            GenericAssessmentInstrument::Rmib => $this->persistRmib->execute(
                $this->scoreRmib->execute($sealed, $instrumentVersionId),
            ),
            default => throw new LogicException('ASSESSMENT_SCORING_INSTRUMENT_UNSUPPORTED'),
        };

        $outcome = AssessmentScoringOutcome::scored($resultPublicId);
        $this->recordAttempt($sessionId, $instrument, $attemptedAt, $outcome);

        return $outcome;
    }

    private function activeInstrumentVersionId(GenericAssessmentInstrument $instrument): int
    {
        $id = DB::table('instrument_versions')
            ->where('code', $instrument->value)
            ->where('is_active', true)
            ->value('id');
        if (! is_int($id) && ! is_string($id)) {
            // No active instrument version for a scorable instrument is a
            // deployment/seeding problem, not a predictable participant
            // outcome -- propagate and let the caller's transaction decide.
            throw new RuntimeException("No active instrument_versions row for '{$instrument->value}'.");
        }

        return (int) $id;
    }

    private function recordAttempt(
        int $sessionId,
        GenericAssessmentInstrument $instrument,
        DateTimeImmutable $attemptedAt,
        AssessmentScoringOutcome $outcome,
    ): void {
        $session = DB::table('test_sessions')->where('id', $sessionId)->first(['id', 'public_id']);
        if ($session === null || ! is_string($session->public_id ?? null)) {
            throw new RuntimeException('Assessment scoring attempt requires an existing session.');
        }

        DB::table('assessment_scoring_attempts')->insert([
            'public_id' => strtoupper((string) Str::ulid()),
            'session_id' => $sessionId,
            'session_public_id' => strtoupper((string) $session->public_id),
            'instrument_code' => $instrument->value,
            'attempted_at' => $attemptedAt->format('Y-m-d H:i:s.uP'),
            'outcome' => $outcome->outcome,
            'reason_code' => $outcome->reasonCode,
            'result_public_id' => $outcome->resultPublicId,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
