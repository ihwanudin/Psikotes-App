<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Actions\Integrations\InvalidCheckoutHandoff;
use App\Data\Integrations\CheckoutSessionScope;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\CheckoutHandoff;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

/** Canonical P13b consume transition for callers that already own a service transaction. */
final readonly class ConsumeCheckoutHandoffTransaction
{
    private const string CONTRACT_VERSION = 'checkout-v2';

    private const string PURPOSE = 'checkout-handoff';

    private const string DESTINATION = 'integrated-checkout-session';

    public function __construct(
        private RlsContextRunner $contexts,
        private CheckoutHandoffHistoryValidator $historyValidator,
    ) {}

    public function execute(#[SensitiveParameter] string $rawToken): ?CheckoutSessionScope
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() === 0) {
            throw new LogicException('Checkout handoff transaction requires service authority.');
        }
        $ttl = config('assessment_integration.checkout_handoff.ttl_seconds');
        if (config('assessment_integration.checkout_handoff.enabled') !== true
            || ! is_int($ttl) || $ttl < 60 || $ttl > 600) {
            throw new InvalidCheckoutHandoff;
        }

        if (! preg_match('/^och1_[0-9a-f]{64}$/D', $rawToken)) {
            throw new InvalidCheckoutHandoff;
        }

        return $this->consume(hash('sha256', $rawToken));
    }

    private function consume(#[SensitiveParameter] string $digest): ?CheckoutSessionScope
    {
        $hints = CheckoutHandoff::query()->where('token_digest', $digest)->limit(2)
            ->get(['id', 'organization_id', 'assessment_participant_id', 'integration_client_id',
                'integration_source_id', 'package_id', 'participant_id']);
        if ($hints->count() !== 1) {
            throw new InvalidCheckoutHandoff;
        }
        $hint = $hints->sole();

        $organization = Branch::query()->lockForUpdate()->find($hint->organization_id);
        if ($organization === null || ! $organization->is_active || $organization->status !== 'ACTIVE') {
            throw new InvalidCheckoutHandoff;
        }
        $client = IntegrationClient::query()->where('organization_id', $organization->id)
            ->lockForUpdate()->find($hint->integration_client_id);
        if ($client === null || ! $client->enabled) {
            throw new InvalidCheckoutHandoff;
        }
        $source = IntegrationSource::query()->where('integration_client_id', $client->id)
            ->where('contract_version', self::CONTRACT_VERSION)->lockForUpdate()->find($hint->integration_source_id);
        if ($source === null || $source->status !== 'ACTIVE') {
            throw new InvalidCheckoutHandoff;
        }
        $package = TestPackage::query()->lockForUpdate()->find($hint->package_id);
        if ($package === null || ! $package->is_active || $package->amount === null || $package->amount < 0
            || $package->currency !== 'IDR'
            || ($package->consultation_amount !== null && $package->consultation_amount < 0)
            || ! in_array($package->code, $source->allowed_assessment_packages, true)
        ) {
            throw new InvalidCheckoutHandoff;
        }
        $packageItems = $package->items()->orderBy('id')->lockForUpdate()->get(['id', 'test_type']);
        try {
            TestPackage::canonicalComposition($packageItems->pluck('test_type')->all());
        } catch (DomainException) {
            throw new InvalidCheckoutHandoff;
        }
        $attempt = AssessmentParticipant::query()->where('organization_id', $organization->id)
            ->where('integration_client_id', $client->id)->where('package_id', $package->id)
            ->where('source_system', $source->source_system)->lockForUpdate()->find($hint->assessment_participant_id);
        if ($attempt === null || $attempt->participant_id !== $hint->participant_id
            || $attempt->assessment_status !== 'PROVISIONED' || $attempt->revoked_at !== null
            || ! is_array($attempt->metadata)
            || ($attempt->metadata['checkout_contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new InvalidCheckoutHandoff;
        }
        $participant = Participant::withTrashed()->where('branch_id', $organization->id)
            ->lockForUpdate()->find($attempt->participant_id);
        if ($participant === null || $participant->deleted_at !== null) {
            throw new InvalidCheckoutHandoff;
        }

        /** @var Collection<int, CheckoutHandoff> $history */
        $history = CheckoutHandoff::query()->where('assessment_participant_id', $attempt->id)
            ->where('purpose', self::PURPOSE)->where('destination', self::DESTINATION)
            ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
        $target = $history->firstWhere('id', $hint->id);
        if ($target === null || ! hash_equals($target->token_digest, $digest)) {
            throw new InvalidCheckoutHandoff;
        }
        if (! $this->historyValidator->valid($history, $attempt, $source)) {
            throw new InvalidCheckoutHandoff;
        }

        $now = $this->databaseNow();
        if (! $this->effective($client->effective_from, $client->effective_until, $now)
            || ! $this->effective($source->effective_from, $source->effective_until, $now)) {
            throw new InvalidCheckoutHandoff;
        }
        if ($now->greaterThanOrEqualTo($target->expires_at)) {
            if ($target->status !== 'ISSUED' || $target->active_marker !== true) {
                throw new InvalidCheckoutHandoff;
            }
            $target->update([
                'active_marker' => null, 'status' => 'EXPIRED', 'expired_at' => $now, 'updated_at' => $now,
            ]);

            return null;
        }
        if ($target->status !== 'ISSUED' || $target->active_marker !== true) {
            throw new InvalidCheckoutHandoff;
        }

        $target->update([
            'active_marker' => null, 'status' => 'CONSUMED', 'consumed_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit($target, $attempt, $client, $now);

        return new CheckoutSessionScope(
            $target->public_id,
            $attempt->id,
            $attempt->assessment_attempt_id,
            $organization->id,
            $participant->id,
            $package->id,
            $client->id,
            $source->id,
            $source->source_system,
            $now,
        );
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

    private function audit(CheckoutHandoff $handoff, AssessmentParticipant $attempt,
        IntegrationClient $client, CarbonImmutable $now): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $attempt->organization_id,
            'actor_type' => 'checkout_handoff',
            'actor_id' => $handoff->public_id,
            'action' => 'checkout_handoff.consumed',
            'subject_type' => AssessmentParticipant::class,
            'subject_id' => (string) $attempt->id,
            'context' => json_encode([
                'version' => 1,
                'publicId' => $handoff->public_id,
                'issueNumber' => $handoff->issue_number,
                'purpose' => self::PURPOSE,
                'destination' => self::DESTINATION,
                'sourceSystem' => $handoff->source_system,
                'integrationClientId' => $client->id,
                'consumedAt' => $now->toISOString(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
            'expires_at' => $now->addYearsNoOverflow(2),
        ]);
    }
}
