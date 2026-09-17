<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Rules\PublicHttpsUrl;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use LogicException;

final readonly class GenericAssessmentResultCallbackDispatcher
{
    private const string CONTRACT = 'generic-assessment-result';

    private const string CONTRACT_VERSION = '1';

    private const string PATH = '/api/v2/integrations/psychotest/results';

    private const string RECEIVER_HOST = 'seleksi.beasiswajepang.id';

    public function __construct(
        private GenericAssessmentResultDispatch $dispatch,
        private GenericAssessmentResultProjector $projector,
        private PsychotestSelectionRequestSigner $signer,
        private RlsContextRunner $runner,
    ) {}

    /** @return array<string,mixed> */
    public function dispatchExact(
        string $outboxId,
        string $resultVersionId,
        int $resultVersion,
        string $resultChecksum,
        string $leaseToken,
    ): array {
        [$url, $secret, $timeout, $keyId] = $this->configuration();
        [$envelope, $claim] = $this->runner->run(new RlsContext('service'), function () use (
            $outboxId,
            $resultVersionId,
            $resultVersion,
            $resultChecksum,
            $leaseToken,
        ): array {
            $envelope = $this->latestEnvelope($outboxId, $resultVersionId, $resultVersion, $resultChecksum);
            $claim = $this->dispatch->claimExact(
                $outboxId, $resultVersionId, $resultVersion, $resultChecksum, $leaseToken,
            );

            return [$envelope, $claim];
        });
        $body = json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (! in_array($claim['action'], ['CLAIMED', 'TAKEN_OVER', 'REPLAYED'], true)) {
            return $claim;
        }

        $timestamp = (string) now()->timestamp;
        $signature = $this->signer->sign($timestamp, 'POST', self::PATH, '', $body, $secret, $keyId);
        $headers = [
            'X-Psychotest-Timestamp' => $timestamp,
            'X-Psychotest-Contract' => self::CONTRACT,
            'X-Psychotest-Contract-Version' => self::CONTRACT_VERSION,
            'X-Psychotest-Signature-Version' => 'v2',
            'X-Psychotest-Signature' => $signature,
        ];
        if ($keyId !== null) {
            $headers['X-Psychotest-Key-Id'] = $keyId;
        }
        try {
            $response = Http::acceptJson()
                ->withHeaders($headers)
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->withBody($body, 'application/json')
                ->post($url);
            [$outcome, $reason] = $this->classify($response);
        } catch (ConnectionException) {
            [$outcome, $reason] = ['UNKNOWN', 'OUTCOME_UNCERTAIN'];
        }

        return $this->runner->run(new RlsContext('service'), fn (): array => $this->dispatch->completeExact(
            $outboxId,
            $resultVersionId,
            $resultVersion,
            $resultChecksum,
            (string) $claim['attemptId'],
            $leaseToken,
            $outcome,
            $reason,
        ));
    }

    /** @return array{string,string,int,?string} */
    private function configuration(): array
    {
        $baseUrl = config('selection_integration.result_callback_base_url');
        $secret = config('selection_integration.result_callback_secret');
        $keyId = config('selection_integration.result_callback_key_id');
        $inboundSecret = config('selection_integration.client_secret');
        $timeout = (int) config('selection_integration.result_callback_timeout_seconds', 10);
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! (bool) config('selection_integration.result_callback_enabled')
            || ! PublicHttpsUrl::accepts($baseUrl)
            || ! is_array($parts)
            || ! is_string($host) || strtolower($host) !== self::RECEIVER_HOST
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['port']) && (int) $parts['port'] !== 443
            || ! is_string($secret) || ! PsychotestSelectionRequestSigner::acceptsSecret($secret)
            || ! PsychotestSelectionRequestSigner::acceptsKeyId($keyId)
            || (is_string($inboundSecret) && hash_equals($inboundSecret, $secret))
            || $timeout < 2 || $timeout > 30) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_CONFIG_INVALID');
        }

        return [rtrim($baseUrl, '/').self::PATH, $secret, $timeout, is_string($keyId) ? $keyId : null];
    }

    /** @return array<string,mixed> */
    private function latestEnvelope(
        string $outboxId,
        string $resultVersionId,
        int $resultVersion,
        string $resultChecksum,
    ): array {
        $participantId = DB::table('generic_assessment_result_outbox')
            ->where('id', $outboxId)
            ->where('generic_assessment_result_version_id', $resultVersionId)
            ->where('result_version', $resultVersion)
            ->where('result_checksum', $resultChecksum)
            ->value('assessment_participant_id');
        if (! is_numeric($participantId)) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SOURCE_NOT_LATEST');
        }

        $participant = DB::table('assessment_participants')
            ->where('id', (int) $participantId)
            ->lockForUpdate()
            ->first(['id', 'integration_client_id']);
        if ($participant === null) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SOURCE_NOT_LATEST');
        }

        $client = DB::table('integration_clients')
            ->where('id', (int) $participant->integration_client_id)
            ->lockForUpdate()
            ->first(['id']);
        if ($client === null) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SOURCE_NOT_LATEST');
        }

        $row = DB::table('generic_assessment_result_outbox as o')
            ->join('generic_assessment_result_versions as r', 'r.id', '=', 'o.generic_assessment_result_version_id')
            ->join('assessment_participants as p', 'p.id', '=', 'o.assessment_participant_id')
            ->join('integration_clients as c', 'c.id', '=', 'p.integration_client_id')
            ->where('o.id', $outboxId)
            ->where('r.id', $resultVersionId)
            ->where('o.result_version', $resultVersion)
            ->where('o.result_checksum', $resultChecksum)
            ->select([
                'o.envelope_contract', 'o.assessment_participant_id as outbox_participant_id',
                'o.assessment_attempt_id as outbox_attempt_id', 'o.result_version as outbox_version',
                'o.result_checksum as outbox_checksum', 'r.assessment_participant_id as source_participant_id',
                'r.assessment_attempt_id as source_attempt_id', 'r.result_version as source_version',
                'r.result_checksum as source_checksum', 'r.iq_canonical', 'r.engine_version',
                'r.completed_at', 'r.finality', 'r.revoked_at', 'c.enabled as client_enabled',
                'c.id as client_id', 'c.result_delivery_mode', 'c.effective_from', 'c.effective_until',
            ])
            ->selectSub(function ($query): void {
                $query->from('generic_assessment_result_versions as latest')
                    ->selectRaw('MAX(latest.result_version)')
                    ->whereColumn('latest.assessment_participant_id', 'o.assessment_participant_id')
                    ->whereColumn('latest.assessment_attempt_id', 'o.assessment_attempt_id');
            }, 'latest_version')
            ->first();
        $now = CarbonImmutable::instance(now())->utc();
        if ($row === null
            || (string) $row->envelope_contract !== 'generic-assessment-result:v1'
            || (int) $row->outbox_participant_id !== (int) $row->source_participant_id
            || (int) $row->client_id !== (int) $client->id
            || (string) $row->outbox_attempt_id !== (string) $row->source_attempt_id
            || (int) $row->outbox_version !== (int) $row->source_version
            || (int) $row->source_version !== (int) $row->latest_version
            || ! hash_equals((string) $row->outbox_checksum, (string) $row->source_checksum)
            || ! (bool) $row->client_enabled
            || ! in_array((string) $row->result_delivery_mode, ['CALLBACK', 'CALLBACK_AND_POLL'], true)
            || ($row->effective_from !== null && CarbonImmutable::parse((string) $row->effective_from)->utc()->gt($now))
            || ($row->effective_until !== null && CarbonImmutable::parse((string) $row->effective_until)->utc()->lte($now))) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SOURCE_NOT_LATEST');
        }

        $iqCanonical = (string) $row->iq_canonical;
        $envelope = [
            'assessmentAttemptId' => (string) $row->source_attempt_id,
            'iq' => str_contains($iqCanonical, '.') || str_contains(strtolower($iqCanonical), 'e')
                ? (float) $iqCanonical
                : (int) $iqCanonical,
            'engineVersion' => (string) $row->engine_version,
            'completedAt' => CarbonImmutable::parse((string) $row->completed_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'finality' => (string) $row->finality,
            'revokedAt' => $row->revoked_at === null
                ? null
                : CarbonImmutable::parse((string) $row->revoked_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'resultVersion' => (int) $row->source_version,
            'resultChecksum' => (string) $row->source_checksum,
        ];
        $this->projector->safeAuditContext($envelope);

        return $envelope;
    }

    /** @return array{string,?string} */
    private function classify(Response $response): array
    {
        $status = $response->status();

        return match (true) {
            in_array($status, [200, 202], true) => $this->classifyAcknowledgement($response),
            $status === 408 => ['UNKNOWN', 'OUTCOME_UNCERTAIN'],
            $status === 429 => ['RETRYABLE', 'RATE_LIMITED'],
            $status >= 500 && $status <= 599 => ['RETRYABLE', 'TRANSIENT_UNAVAILABLE'],
            default => ['PERMANENT', 'REMOTE_REJECTED'],
        };
    }

    /** @return array{string,?string} */
    private function classifyAcknowledgement(Response $response): array
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
        $body = $response->body();
        if ($contentType !== 'application/json' || $body === '' || strlen($body) > 4096) {
            return ['UNKNOWN', 'OUTCOME_UNCERTAIN'];
        }

        try {
            $decoded = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['UNKNOWN', 'OUTCOME_UNCERTAIN'];
        }

        $expectedStatus = $response->status() === 202 ? 'ACCEPTED' : 'REPLAYED';
        if (! is_array($decoded)
            || array_keys($decoded) !== ['data']
            || ! is_array($decoded['data'])
            || array_keys($decoded['data']) !== ['status']
            || $decoded['data']['status'] !== $expectedStatus) {
            return ['UNKNOWN', 'OUTCOME_UNCERTAIN'];
        }

        return ['ACKNOWLEDGED', null];
    }
}
