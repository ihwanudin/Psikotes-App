<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentBillManualReview;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Enums\AssessmentBillManualReviewError;
use App\Exceptions\AssessmentBillManualReviewException;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Policies\AssessmentBillPolicy;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;

/** Internal bridge from a persisted proof-access audit to the canonical manual finalizer. */
final readonly class ReviewAssessmentBillFromProofAccess
{
    private const string ACCESS_ACTION = 'assessment_bill.proof_temporary_url_issued';

    private const string ACCESS_SOURCE = 'assessment_bill_manual_review';

    private const string CONTEXT_INVALID = 'ASSESSMENT_BILL_PROOF_REVIEW_CONTEXT_INVALID';

    public function __construct(
        private RlsContextRunner $contexts,
        private ReviewAssessmentBillTransfer $reviews,
        private AssessmentBillPolicy $policy,
    ) {}

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    public function execute(
        Admin $actor,
        string $billReference,
        AssessmentBillManualDecision $decision,
        ?AssessmentBillManualRejectionCode $rejectionCode,
    ): array {
        if ($this->contexts->current() !== null || DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Proof-backed assessment review requires isolated phases.');
        }

        $fingerprint = $this->contexts->run(new RlsContext('service'),
            fn (): string => $this->latestCanonicalFingerprint($actor, $billReference));

        return $this->reviews->execute(new AssessmentBillManualReview(
            actorAdminId: (int) $actor->getKey(),
            billReference: $billReference,
            expectedProofFingerprint: $fingerprint,
            decision: $decision,
            rejectionCode: $rejectionCode,
        ));
    }

    private function latestCanonicalFingerprint(Admin $actor, string $billReference): string
    {
        $actorId = $actor->getKey();
        if (! is_int($actorId)) {
            $this->notFound();
        }
        if (! $this->policy->viewAny($actor)) {
            $this->notFound();
        }
        if (! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $billReference)) {
            $this->notFound();
        }
        $bill = AssessmentBill::query()->where('public_reference', $billReference)
            ->first(['id', 'organization_id']);
        if ($bill === null) {
            $this->notFound();
        }

        $audits = DB::table('audit_logs')
            ->where('branch_id', $bill->organization_id)
            ->where('actor_type', 'admin')
            ->where('actor_id', (string) $actorId)
            ->where('action', self::ACCESS_ACTION)
            ->where('subject_type', AssessmentBill::class)
            ->where('subject_id', (string) $bill->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get(['id', 'context', 'occurred_at'])
            ->map(fn (object $row): array => $this->auditRow($row));
        if ($audits->isEmpty()) {
            $this->contextInvalid();
        }

        $latest = $this->canonicalContext($audits->first());
        $previous = $audits->get(1);
        if ($previous !== null
            && $this->canonicalOccurredAt($previous)->equalTo($latest['occurredAt'])) {
            $previousContext = $this->canonicalContext($previous);
            if ($previousContext['fingerprint'] !== $latest['fingerprint']
                || ! $previousContext['expiresAt']->equalTo($latest['expiresAt'])) {
                $this->contextInvalid();
            }
        }

        return $latest['fingerprint'];
    }

    /** @return array{context: mixed, occurred_at: mixed} */
    private function auditRow(object $row): array
    {
        $values = (array) $row;
        if (! array_key_exists('context', $values) || ! array_key_exists('occurred_at', $values)) {
            $this->contextInvalid();
        }

        return ['context' => $values['context'], 'occurred_at' => $values['occurred_at']];
    }

    /** @param array{context: mixed, occurred_at: mixed} $audit
     * @return array{fingerprint: string, expiresAt: CarbonImmutable, occurredAt: CarbonImmutable}
     */
    private function canonicalContext(array $audit): array
    {
        $occurredAt = $this->canonicalOccurredAt($audit);
        try {
            $context = json_decode((string) $audit['context'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->contextInvalid();
        }
        if (! is_array($context)
            || array_keys($context) !== ['version', 'source', 'proof_fingerprint', 'url_expires_at']
            || ($context['version'] ?? null) !== 1
            || ($context['source'] ?? null) !== self::ACCESS_SOURCE
            || ! is_string($context['proof_fingerprint'] ?? null)
            || ! preg_match('/^[0-9a-f]{64}$/D', $context['proof_fingerprint'])
            || ! is_string($context['url_expires_at'] ?? null)) {
            $this->contextInvalid();
        }
        $rawExpiry = $context['url_expires_at'];
        try {
            $expiresAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i:sP', $rawExpiry);
        } catch (Throwable) {
            $this->contextInvalid();
        }
        if (! $expiresAt instanceof CarbonImmutable
            || $expiresAt->format('Y-m-d\TH:i:sP') !== $rawExpiry
            || $expiresAt->offset !== 0
            || ! $expiresAt->isFuture()
            || ! $expiresAt->greaterThan($occurredAt)
            || $expiresAt->greaterThan($occurredAt->addMinutes(60))) {
            $this->contextInvalid();
        }

        return ['fingerprint' => $context['proof_fingerprint'], 'expiresAt' => $expiresAt,
            'occurredAt' => $occurredAt];
    }

    /** @param array{occurred_at: mixed} $audit */
    private function canonicalOccurredAt(array $audit): CarbonImmutable
    {
        $raw = (string) $audit['occurred_at'];
        if (preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[ T](?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.\d{1,6})?(?:Z|\+00(?::?00)?)?$/D',
            $raw,
            $parts,
        ) !== 1
            || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
            || (int) $parts['hour'] > 23
            || (int) $parts['minute'] > 59
            || (int) $parts['second'] > 59) {
            $this->contextInvalid();
        }
        try {
            $parsed = CarbonImmutable::parse($raw, 'UTC');
        } catch (Throwable) {
            $this->contextInvalid();
        }
        if ($parsed->offset !== 0) {
            $this->contextInvalid();
        }
        $occurredAt = $parsed->utc();
        if ($occurredAt->isFuture()) {
            $this->contextInvalid();
        }

        return $occurredAt;
    }

    private function notFound(): never
    {
        throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::NotFound);
    }

    private function contextInvalid(): never
    {
        throw new DomainException(self::CONTEXT_INVALID);
    }
}
