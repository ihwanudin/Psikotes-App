<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/** Unwired participant boundary that owns the complete service transaction. */
final readonly class StartParticipantAssessmentSession
{
    private const MAX_TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private RlsContextRunner $contexts,
        private ParticipantAssessmentSessionCandidates $candidates,
        private AssessmentSessionSelectionPolicy $selectionPolicy,
        private CaseAuthorizationResolver $authorizations,
        private AllocateAndStartAssessmentSession $allocator,
    ) {}

    public function execute(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): AssessmentSessionAllocationResult {
        $this->assertCleanOuterBoundary();

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                /** @var AssessmentSessionAllocationResult $result */
                $result = $this->contexts->runAsService(
                    fn (): AssessmentSessionAllocationResult => $this->withinTransaction($principal, $instrument),
                );

                return $result;
            } catch (QueryException $exception) {
                if ($attempt === self::MAX_TRANSACTION_ATTEMPTS || ! $this->isRetryable($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Participant assessment session start exhausted its transaction attempts.');
    }

    private function withinTransaction(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): AssessmentSessionAllocationResult {
        $projection = $this->candidates->project($principal, $instrument);
        $selection = $this->selectionPolicy->select($projection->scope, $projection->candidates);
        if ($selection->candidate === null) {
            throw new InvalidAssessmentSessionState($selection->kind->value);
        }

        $authorization = $this->authorizations->resolveSelectedParticipantForUpdate(
            $principal,
            $selection->candidate,
        );

        return $this->allocator->allocateSelectedParticipantForUpdate(
            $principal,
            $instrument,
            $authorization,
        );
    }

    private function assertCleanOuterBoundary(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Participant assessment session start must own its outer service transaction.');
        }
    }

    private function isRetryable(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return in_array((string) $sqlState, ['40001', '40P01'], true);
    }
}
