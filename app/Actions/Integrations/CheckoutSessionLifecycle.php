<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Data\Integrations\CheckoutPaymentFacts;
use App\Data\Integrations\CheckoutProfile;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Data\Integrations\CheckoutSessionSelector;
use App\Enums\CheckoutSessionOperation;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\CheckoutHandoff;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutHandoffHistoryValidator;
use App\Services\Integrations\CheckoutPaymentFactsReader;
use App\Services\Integrations\CheckoutProfileMapper;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

/** Internal P14a3 lifecycle boundary. It owns service context and transaction. */
final readonly class CheckoutSessionLifecycle
{
    private const string CONTRACT_VERSION = 'checkout-v2';

    private const string PURPOSE = 'checkout-handoff';

    private const string DESTINATION = 'integrated-checkout-session';

    public function __construct(
        private RlsContextRunner $contexts,
        private CheckoutHandoffHistoryValidator $historyValidator,
        private CheckoutProfileMapper $profiles,
        private CheckoutPaymentFactsReader $payments,
    ) {}

    public function hydrate(#[SensitiveParameter] CheckoutSessionSelector $input): CheckoutSessionPrincipal
    {
        $idle = $this->preflight();
        $selectorDigest = $this->selectorDigest($input->rawSelector());

        /** @var CheckoutSessionPrincipal|null $principal */
        $principal = $this->contexts->run(
            new RlsContext('service'),
            function () use ($selectorDigest, $idle): ?CheckoutSessionPrincipal {
                $result = $this->operate($selectorDigest, null, CheckoutSessionOperation::Hydrate, $idle);

                return $result instanceof CheckoutSessionPrincipal ? $result : null;
            },
        );
        if ($principal === null) {
            throw new InvalidCheckoutSession;
        }

        return $principal;
    }

    public function hydrateWithCsrfDelivery(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $input,
    ): CheckoutSessionPrincipal {
        $idle = $this->preflight();
        $selectorDigest = $this->selectorDigest($input->rawSelector());
        $csrfDigest = $this->csrfDigest($input->rawCsrfToken());

        /** @var CheckoutSessionPrincipal|null $principal */
        $principal = $this->contexts->run(
            new RlsContext('service'),
            function () use ($selectorDigest, $csrfDigest, $idle): ?CheckoutSessionPrincipal {
                $result = $this->operate($selectorDigest, $csrfDigest, CheckoutSessionOperation::HydrateWithCsrfDelivery, $idle);

                return $result instanceof CheckoutSessionPrincipal ? $result : null;
            },
        );
        if ($principal === null) {
            throw new InvalidCheckoutSession;
        }

        return $principal;
    }

    /** Profile projection stays inside canonical graph locks; successful reads refresh session idle time. */
    public function readProfile(#[SensitiveParameter] CheckoutSessionMutationCredentials $input): CheckoutProfile
    {
        $idle = $this->preflight();
        $selectorDigest = $this->selectorDigest($input->rawSelector());
        $csrfDigest = $this->csrfDigest($input->rawCsrfToken());

        $profile = $this->contexts->run(
            new RlsContext('service'),
            function () use ($selectorDigest, $csrfDigest, $idle): ?CheckoutProfile {
                $result = $this->operate($selectorDigest, $csrfDigest, CheckoutSessionOperation::Profile, $idle);

                return $result instanceof CheckoutProfile ? $result : null;
            },
        );
        // Preserve committed expiry/revocation before returning the same generic lifecycle denial.
        if ($profile === null) {
            throw new InvalidCheckoutSession;
        }

        return $profile;
    }

    /** Historical own payment facts, not purchasing policy, consent, or access entitlement. */
    public function readPayment(#[SensitiveParameter] CheckoutSessionMutationCredentials $input): CheckoutPaymentFacts
    {
        $idle = $this->preflight();
        $selectorDigest = $this->selectorDigest($input->rawSelector());
        $csrfDigest = $this->csrfDigest($input->rawCsrfToken());
        $payment = $this->contexts->run(new RlsContext('service'),
            function () use ($selectorDigest, $csrfDigest, $idle): ?CheckoutPaymentFacts {
                $result = $this->operate($selectorDigest, $csrfDigest, CheckoutSessionOperation::Payment, $idle);

                return $result instanceof CheckoutPaymentFacts ? $result : null;
            });
        if ($payment === null) {
            throw new InvalidCheckoutSession;
        }

        return $payment;
    }

    public function logout(#[SensitiveParameter] CheckoutSessionMutationCredentials $input): void
    {
        $idle = $this->preflight();
        $selectorDigest = $this->selectorDigest($input->rawSelector());
        $csrfDigest = $this->csrfDigest($input->rawCsrfToken());

        /** @var bool $revoked */
        $revoked = $this->contexts->run(
            new RlsContext('service'),
            fn (): bool => $this->operate($selectorDigest, $csrfDigest, CheckoutSessionOperation::Logout, $idle) === true,
        );
        if ($revoked !== true) {
            throw new InvalidCheckoutSession;
        }
    }

    private function operate(#[SensitiveParameter] string $selectorDigest,
        #[SensitiveParameter] ?string $csrfDigest, CheckoutSessionOperation $operation,
        int $idleMinutes): CheckoutPaymentFacts|CheckoutProfile|CheckoutSessionPrincipal|bool|null
    {
        $hints = CheckoutSession::query()->where('selector_digest', $selectorDigest)->limit(2)
            ->get(['id', 'organization_id', 'assessment_participant_id', 'integration_client_id',
                'integration_source_id', 'package_id', 'participant_id']);
        if ($hints->count() !== 1) {
            throw new InvalidCheckoutSession;
        }
        $hint = $hints->sole();

        $organization = Branch::query()->lockForUpdate()->find($hint->organization_id);
        $client = IntegrationClient::query()->where('organization_id', $hint->organization_id)
            ->lockForUpdate()->find($hint->integration_client_id);
        $source = IntegrationSource::query()->where('integration_client_id', $hint->integration_client_id)
            ->where('contract_version', self::CONTRACT_VERSION)->lockForUpdate()->find($hint->integration_source_id);
        $package = TestPackage::query()->lockForUpdate()->find($hint->package_id);
        if ($organization === null || $client === null || $source === null || $package === null) {
            throw new InvalidCheckoutSession;
        }
        $packageItems = $package->items()->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $attempt = AssessmentParticipant::query()->where('organization_id', $organization->id)
            ->where('integration_client_id', $client->id)->where('package_id', $package->id)
            ->where('source_system', $source->source_system)->lockForUpdate()->find($hint->assessment_participant_id);
        if ($attempt === null) {
            throw new InvalidCheckoutSession;
        }
        $participant = Participant::withTrashed()->where('branch_id', $organization->id)
            ->lockForUpdate()->find($hint->participant_id);
        if ($participant === null) {
            throw new InvalidCheckoutSession;
        }

        /** @var Collection<int, CheckoutHandoff> $handoffs */
        $handoffs = CheckoutHandoff::query()->where('assessment_participant_id', $attempt->id)
            ->where('purpose', self::PURPOSE)->where('destination', self::DESTINATION)
            ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
        /** @var Collection<int, CheckoutSession> $sessions */
        $sessions = CheckoutSession::query()->where('assessment_participant_id', $attempt->id)
            ->orderBy('established_at')->orderBy('id')->lockForUpdate()->get();
        $target = $sessions->firstWhere('id', $hint->id);
        if (! $target instanceof CheckoutSession || ! hash_equals($target->selector_digest, $selectorDigest)) {
            throw new InvalidCheckoutSession;
        }
        if ($operation !== CheckoutSessionOperation::Hydrate && ($csrfDigest === null || ! hash_equals($target->csrf_digest, $csrfDigest))) {
            throw new InvalidCheckoutSession;
        }

        $now = $this->databaseNow();
        if (! $this->historyValidator->valid($handoffs, $attempt, $source)) {
            throw new InvalidCheckoutSession;
        }
        $latestHandoff = $handoffs->last();
        if (! $latestHandoff instanceof CheckoutHandoff) {
            throw new InvalidCheckoutSession;
        }
        $this->assertSessions($sessions, $handoffs, $attempt, $target, $now);
        if ($target->status !== 'ACTIVE' || $target->active_marker !== true
            || $target->revoked_at !== null || $target->expired_at !== null
            || $target->revocation_reason !== null) {
            throw new InvalidCheckoutSession;
        }
        if ($now->greaterThanOrEqualTo($target->idle_expires_at)
            || $now->greaterThanOrEqualTo($target->absolute_expires_at)) {
            $this->terminalize($target, $attempt, $now, 'EXPIRED', null);

            return null;
        }

        $exactScope = $target->organization_id === $organization->id
            && $target->assessment_participant_id === $attempt->id
            && $target->participant_id === $participant->id
            && $target->package_id === $package->id
            && $target->integration_client_id === $client->id
            && $target->integration_source_id === $source->id
            && $target->source_system === $source->source_system
            && $target->contract_version === self::CONTRACT_VERSION
            && $attempt->participant_id === $participant->id
            && $latestHandoff->id === $target->checkout_handoff_id
            && $latestHandoff->status === 'CONSUMED';
        if (! $exactScope) {
            throw new InvalidCheckoutSession;
        }

        $scopeActive = $organization->is_active && $organization->status === 'ACTIVE'
            && $client->enabled && $this->effective($client->effective_from, $client->effective_until, $now)
            && $source->status === 'ACTIVE' && $this->effective($source->effective_from, $source->effective_until, $now)
            && $package->is_active && $package->amount !== null && $package->amount >= 0
            && $package->currency === 'IDR'
            && ($package->consultation_amount === null || $package->consultation_amount >= 0)
            && in_array($package->code, $source->allowed_assessment_packages, true)
            && $packageItems !== []
            && $participant->deleted_at === null && $attempt->revoked_at === null
            && ! in_array($attempt->assessment_status, ['REVOKED', 'VOID'], true)
            && is_array($attempt->metadata)
            && ($attempt->metadata['checkout_contract_version'] ?? null) === self::CONTRACT_VERSION;
        if (! $scopeActive) {
            $this->terminalize($target, $attempt, $now, 'REVOKED', 'SCOPE_REVOKED');

            return null;
        }
        if ($operation === CheckoutSessionOperation::Logout) {
            $this->terminalize($target, $attempt, $now, 'REVOKED', 'LOGOUT');

            return true;
        }

        $candidateIdle = $now->addMinutes($idleMinutes);
        $absolute = CarbonImmutable::instance($target->absolute_expires_at)->utc();
        $idleExpiresAt = $candidateIdle->lessThan($absolute) ? $candidateIdle : $absolute;
        $target->update([
            'last_seen_at' => $now,
            'idle_expires_at' => $idleExpiresAt,
            'updated_at' => $now,
        ]);

        if ($operation === CheckoutSessionOperation::Profile) {
            return $this->profiles->map($participant,
                $now->setTimezone(date_default_timezone_get())->toDateTimeImmutable());
        }

        if ($operation === CheckoutSessionOperation::Payment) {
            return $this->payments->project($attempt);
        }

        return new CheckoutSessionPrincipal(
            $target->public_id,
            $latestHandoff->public_id,
            $attempt->id,
            $attempt->assessment_attempt_id,
            $organization->id,
            $participant->id,
            $package->id,
            $client->id,
            $source->id,
            $source->source_system,
            $attempt->assessment_status,
            $attempt->funding_mode,
            $target->established_at,
            $now,
            $idleExpiresAt,
            $absolute,
        );
    }

    private function preflight(): int
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout session lifecycle owns its service transaction.');
        }
        $enabled = config('assessment_integration.checkout_session.enabled');
        $idle = config('assessment_integration.checkout_session.idle_minutes');
        $absolute = config('assessment_integration.checkout_session.absolute_minutes');
        $retention = config('assessment_integration.checkout_session.terminal_retention_days');
        if ($enabled !== true || ! is_int($idle) || $idle < 1 || $idle > 120
            || ! is_int($absolute) || $absolute < 1 || $absolute > 1440
            || ! is_int($retention) || $retention < 1 || $retention > 365 || $idle > $absolute) {
            throw new LogicException('Checkout session lifecycle is unavailable.');
        }

        return $idle;
    }

    private function selectorDigest(#[SensitiveParameter] string $raw): string
    {
        if (! preg_match('/^ocs1_[0-9a-f]{64}$/D', $raw)) {
            throw new InvalidCheckoutSession;
        }

        return hash('sha256', $raw);
    }

    private function csrfDigest(#[SensitiveParameter] string $raw): string
    {
        if (! preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $raw)) {
            throw new InvalidCheckoutSession;
        }

        return hash('sha256', $raw);
    }

    /** @param Collection<int, CheckoutSession> $sessions
     * @param  Collection<int, CheckoutHandoff>  $handoffs
     */
    private function assertSessions(Collection $sessions, Collection $handoffs, AssessmentParticipant $attempt,
        CheckoutSession $target, CarbonImmutable $now): void
    {
        $handoffsById = $handoffs->keyBy('id');
        $active = 0;
        foreach ($sessions as $session) {
            $handoff = $handoffsById->get($session->checkout_handoff_id);
            $scope = $handoff instanceof CheckoutHandoff
                && $session->assessment_participant_id === $attempt->id
                && $session->organization_id === $attempt->organization_id
                && $session->participant_id === $attempt->participant_id
                && $session->package_id === $attempt->package_id
                && $session->integration_client_id === $attempt->integration_client_id
                && $session->integration_source_id === $handoff->integration_source_id
                && $session->source_system === $attempt->source_system
                && $session->contract_version === self::CONTRACT_VERSION
                && $handoff->status === 'CONSUMED'
                && $handoff->consumed_at !== null
                && $session->established_at->greaterThanOrEqualTo($handoff->consumed_at)
                && $session->established_at->lessThan($handoff->expires_at);
            $valid = match ($session->status) {
                'ACTIVE' => $session->active_marker === true && $session->revoked_at === null
                    && $session->expired_at === null && $session->revocation_reason === null,
                'REVOKED' => $session->active_marker === null && $session->revoked_at !== null
                    && $session->expired_at === null
                    && in_array($session->revocation_reason,
                        ['LOGOUT', 'RECOVERY_REISSUED', 'SCOPE_REVOKED', 'REPLACED'], true)
                    && $session->revoked_at->greaterThanOrEqualTo($session->last_seen_at)
                    && $session->revoked_at->lessThanOrEqualTo($now),
                'EXPIRED' => $session->active_marker === null && $session->revoked_at === null
                    && $session->expired_at !== null && $session->revocation_reason === null
                    && $session->expired_at->greaterThanOrEqualTo(
                        $session->idle_expires_at->lessThan($session->absolute_expires_at)
                            ? $session->idle_expires_at : $session->absolute_expires_at,
                    )
                    && $session->expired_at->lessThanOrEqualTo($now),
                default => false,
            };
            if (! $scope || ! $valid
                || ! preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $session->public_id)
                || ! preg_match('/^[0-9a-f]{64}$/D', $session->selector_digest)
                || ! preg_match('/^[0-9a-f]{64}$/D', $session->csrf_digest)
                || trim($session->source_system) === ''
                || $session->last_seen_at->lessThan($session->established_at)
                || ! $session->idle_expires_at->greaterThan($session->last_seen_at)
                || $session->idle_expires_at->greaterThan($session->absolute_expires_at)
                || ! $session->absolute_expires_at->greaterThan($session->established_at)) {
                throw new InvalidCheckoutSession;
            }
            if ($session->status === 'ACTIVE') {
                $active++;
            }
        }
        if ($active !== 1 || ! $sessions->contains('id', $target->id)) {
            throw new InvalidCheckoutSession;
        }
    }

    private function databaseNow(): CarbonImmutable
    {
        $clock = DB::getDriverName() === 'pgsql' ? 'clock_timestamp()::timestamptz(0)' : 'CURRENT_TIMESTAMP';
        $row = DB::selectOne("SELECT {$clock} AS current_time");
        if ($row === null || (! is_string($row->current_time) && ! $row->current_time instanceof \DateTimeInterface)) {
            throw new LogicException('Database clock is unavailable.');
        }

        return CarbonImmutable::parse($row->current_time)->utc()->startOfSecond();
    }

    private function effective(?CarbonInterface $from, ?CarbonInterface $until, CarbonInterface $now): bool
    {
        return ($from === null || $from->lessThanOrEqualTo($now))
            && ($until === null || $until->greaterThan($now));
    }

    private function terminalize(CheckoutSession $session, AssessmentParticipant $attempt, CarbonImmutable $now,
        string $status, ?string $reason): void
    {
        $expired = $status === 'EXPIRED';
        $session->update([
            'status' => $status,
            'active_marker' => null,
            'revoked_at' => $expired ? null : $now,
            'expired_at' => $expired ? $now : null,
            'revocation_reason' => $reason,
            'updated_at' => $now,
        ]);
        DB::table('audit_logs')->insert([
            'branch_id' => $attempt->organization_id,
            'actor_type' => 'checkout_session',
            'actor_id' => $session->public_id,
            'action' => $expired ? 'checkout_session.expired' : 'checkout_session.revoked',
            'subject_type' => AssessmentParticipant::class,
            'subject_id' => (string) $attempt->id,
            'context' => json_encode([
                'version' => 1,
                'sessionPublicId' => $session->public_id,
                'sourceSystem' => $session->source_system,
                'reason' => $expired ? 'IDLE_OR_ABSOLUTE_EXPIRY' : $reason,
                'transitionedAt' => $now->toISOString(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
            'expires_at' => $now->addYearsNoOverflow(2),
        ]);
    }
}
