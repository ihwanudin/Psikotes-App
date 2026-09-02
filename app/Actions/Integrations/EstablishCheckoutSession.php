<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionScope;
use App\Data\Integrations\EstablishedCheckoutSession;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutHandoff;
use App\Models\CheckoutSession;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\ConsumeCheckoutHandoffTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/** Internal P14a2 boundary. It establishes no HTTP/global Laravel session. */
final readonly class EstablishCheckoutSession
{
    private const string CONTRACT_VERSION = 'checkout-v2';

    public function __construct(
        private RlsContextRunner $contexts,
        private ConsumeCheckoutHandoffTransaction $consumer,
    ) {}

    public function execute(#[SensitiveParameter] CheckoutSessionExchangeInput $input): EstablishedCheckoutSession
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout session establishment owns its service transaction.');
        }
        [$idleMinutes, $absoluteMinutes] = $this->durations();
        $rawSelector = 'ocs1_'.bin2hex(random_bytes(32));
        $rawCsrfToken = 'ocsrf1_'.bin2hex(random_bytes(32));

        /** @var array{scope:CheckoutSessionScope,sessionPublicId:string,establishedAt:CarbonImmutable,idleExpiresAt:CarbonImmutable,absoluteExpiresAt:CarbonImmutable}|null $outcome */
        $outcome = $this->contexts->run(new RlsContext('service'), function () use (
            $input, $idleMinutes, $absoluteMinutes, $rawSelector, $rawCsrfToken,
        ): ?array {
            $scope = $this->consumer->execute($input->rawHandoffToken());
            if ($scope === null) {
                return null;
            }

            CheckoutSession::query()->where('assessment_participant_id', $scope->assessmentParticipantId)
                ->orderBy('established_at')->orderBy('id')->lockForUpdate()->get();
            if (CheckoutSession::query()->where('assessment_participant_id', $scope->assessmentParticipantId)
                ->where('status', 'ACTIVE')->exists()) {
                throw new LogicException('Checkout session state requires recovery.');
            }
            $handoff = CheckoutHandoff::query()->where('public_id', $scope->handoffPublicId)
                ->where('status', 'CONSUMED')->where('assessment_participant_id', $scope->assessmentParticipantId)
                ->sole();
            if (CheckoutSession::query()->where('checkout_handoff_id', $handoff->id)->exists()) {
                throw new LogicException('Checkout session state requires recovery.');
            }

            $establishedAt = CarbonImmutable::instance($scope->consumedAt)->utc();
            $idleExpiresAt = $establishedAt->addMinutes($idleMinutes);
            $absoluteExpiresAt = $establishedAt->addMinutes($absoluteMinutes);
            $session = CheckoutSession::query()->create([
                'public_id' => (string) Str::ulid(),
                'selector_digest' => hash('sha256', $rawSelector),
                'csrf_digest' => hash('sha256', $rawCsrfToken),
                'checkout_handoff_id' => $handoff->id,
                'assessment_participant_id' => $scope->assessmentParticipantId,
                'organization_id' => $scope->organizationId,
                'participant_id' => $scope->participantId,
                'package_id' => $scope->packageId,
                'integration_client_id' => $scope->integrationClientId,
                'integration_source_id' => $scope->integrationSourceId,
                'source_system' => $scope->sourceSystem,
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'ACTIVE',
                'active_marker' => true,
                'established_at' => $establishedAt,
                'last_seen_at' => $establishedAt,
                'idle_expires_at' => $idleExpiresAt,
                'absolute_expires_at' => $absoluteExpiresAt,
            ]);
            $this->audit($session, $scope, $establishedAt, $idleExpiresAt, $absoluteExpiresAt);

            return compact('scope', 'establishedAt', 'idleExpiresAt', 'absoluteExpiresAt') + [
                'sessionPublicId' => $session->public_id,
            ];
        });
        if ($outcome === null) {
            throw new InvalidCheckoutHandoff;
        }
        $scope = $outcome['scope'];

        return new EstablishedCheckoutSession(
            $outcome['sessionPublicId'],
            $scope->handoffPublicId,
            $scope->assessmentParticipantId,
            $scope->assessmentAttemptId,
            $scope->organizationId,
            $scope->participantId,
            $scope->packageId,
            $scope->integrationClientId,
            $scope->integrationSourceId,
            $scope->sourceSystem,
            $outcome['establishedAt'],
            $outcome['idleExpiresAt'],
            $outcome['absoluteExpiresAt'],
            $rawSelector,
            $rawCsrfToken,
        );
    }

    /** @return array{int,int} */
    private function durations(): array
    {
        $enabled = config('assessment_integration.checkout_session.enabled');
        $idle = config('assessment_integration.checkout_session.idle_minutes');
        $absolute = config('assessment_integration.checkout_session.absolute_minutes');
        $retention = config('assessment_integration.checkout_session.terminal_retention_days');
        if ($enabled !== true || ! is_int($idle) || $idle < 1 || $idle > 120
            || ! is_int($absolute) || $absolute < 1 || $absolute > 1440
            || ! is_int($retention) || $retention < 1 || $retention > 365
            || $idle > $absolute) {
            throw new LogicException('Checkout session establishment is unavailable.');
        }

        return [$idle, $absolute];
    }

    private function audit(CheckoutSession $session, CheckoutSessionScope $scope, CarbonImmutable $establishedAt,
        CarbonImmutable $idleExpiresAt, CarbonImmutable $absoluteExpiresAt): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $scope->organizationId,
            'actor_type' => 'checkout_session',
            'actor_id' => $session->public_id,
            'action' => 'checkout_session.established',
            'subject_type' => AssessmentParticipant::class,
            'subject_id' => (string) $scope->assessmentParticipantId,
            'context' => json_encode([
                'version' => 1,
                'sessionPublicId' => $session->public_id,
                'handoffPublicId' => $scope->handoffPublicId,
                'sourceSystem' => $scope->sourceSystem,
                'establishedAt' => $establishedAt->toISOString(),
                'idleExpiresAt' => $idleExpiresAt->toISOString(),
                'absoluteExpiresAt' => $absoluteExpiresAt->toISOString(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $establishedAt,
            'expires_at' => $establishedAt->addYearsNoOverflow(2),
        ]);
    }
}
