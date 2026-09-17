<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\SelectionParticipant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class ConsumeSelectionLaunchTicket
{
    public function __construct(
        private RlsContextRunner $runner,
        private ParticipantJwt $participantJwt,
    ) {}

    public function handle(string $ticket): string
    {
        $baseUrl = $this->baseUrl();
        $clientId = (string) config('selection_integration.client_id');
        $secret = (string) config('selection_integration.client_secret');

        if (! (bool) config('selection_integration.enabled')
            || $clientId === ''
            || strlen($secret) < 32) {
            throw new SelectionLaunchRejected('Layanan psikotes belum tersedia.', 503);
        }

        $body = json_encode(['ticket' => $ticket], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) Date::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), $secret);

        try {
            $response = Http::acceptJson()
                ->withBody($body, 'application/json')
                ->withHeaders([
                    'X-Client-Id' => $clientId,
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => $signature,
                    'X-Request-Id' => (string) Str::uuid(),
                ])
                ->connectTimeout(min(5, $this->timeout()))
                ->timeout($this->timeout())
                ->post($baseUrl.'/api/v1/integrations/psychotest/launch-tickets/consume');
        } catch (ConnectionException) {
            throw new SelectionLaunchRejected('Layanan verifikasi psikotes sedang tidak dapat dihubungi.', 503);
        }

        if (! $response->successful()) {
            $this->rejectProviderResponse($response);
        }

        $candidateId = $response->json('data.candidateId');
        $roundId = $response->json('data.selectionRoundId');
        $participantId = $response->json('data.participantId');

        if (! is_string($candidateId) || $candidateId === ''
            || ! is_string($roundId) || $roundId === ''
            || ! is_string($participantId) || ! ctype_digit($participantId)) {
            throw new SelectionLaunchRejected('Tautan psikotes tidak dapat diverifikasi.', 422);
        }

        $mapping = $this->runner->run(
            new RlsContext('service'),
            fn (): ?SelectionParticipant => SelectionParticipant::query()
                ->with('participant')
                ->where('client_id', $clientId)
                ->where('external_candidate_id', $candidateId)
                ->where('selection_round_id', $roundId)
                ->where('participant_id', (int) $participantId)
                ->first(),
        );

        if ($mapping?->participant === null) {
            throw new SelectionLaunchRejected('Tautan psikotes tidak dapat diverifikasi.', 422);
        }

        return $this->participantJwt->issue(
            $mapping->participant->id,
            $mapping->participant->branch_id,
        );
    }

    private function rejectProviderResponse(Response $response): never
    {
        if (in_array($response->status(), [409, 410], true)) {
            throw new SelectionLaunchRejected('Tautan psikotes sudah digunakan atau tidak lagi berlaku.', 409);
        }

        throw new SelectionLaunchRejected('Layanan verifikasi psikotes sedang tidak dapat dihubungi.', 503);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('selection_integration.selection_base_url'), '/');
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $localHttpAllowed = (bool) config('selection_integration.allow_insecure_local_http')
            && app()->environment('local', 'testing')
            && in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true);

        if ($url === '' || ($scheme !== 'https' && ! ($scheme === 'http' && $localHttpAllowed))) {
            throw new SelectionLaunchRejected('Layanan psikotes belum tersedia.', 503);
        }

        return $url;
    }

    private function timeout(): int
    {
        return max(2, min(30, (int) config('selection_integration.timeout_seconds', 10)));
    }
}
