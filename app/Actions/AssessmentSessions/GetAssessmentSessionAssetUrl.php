<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). GET
 * /sessions/{id}/assets/{assetId}/url. Read-only, same sealed-boundary and
 * "readable exactly when writable" gate as GetAssessmentSessionItems: only
 * `in_progress` and before `ends_at` (SESSION_NOT_STARTED/SESSION_CLOSED/
 * DEADLINE_EXCEEDED), never writes. GET rather than POST because issuing a
 * temporary URL changes no persisted state (Lead's plan review).
 *
 * The asset's `instrument` must match the session's own instrument -- an
 * IST session cannot mint a URL for some other instrument's asset_id, even
 * though nothing else registers asset references yet. Expiry is
 * min(now + config('assessment_assets.temporary_url_minutes'), ends_at):
 * never outlives the session, never exceeds the configured ceiling.
 */
final class GetAssessmentSessionAssetUrl
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId, string $assetId): GetAssessmentSessionAssetUrlResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId) || ! Str::isUlid($assetId)) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        /** @var GetAssessmentSessionAssetUrlResult $result */
        $result = $this->contexts->runAsService(
            fn (): GetAssessmentSessionAssetUrlResult => $this->load($participantId, $sessionPublicId, $assetId),
        );

        return $result;
    }

    private function load(int $participantId, string $sessionPublicId, string $assetId): GetAssessmentSessionAssetUrlResult
    {
        $session = DB::table('test_sessions')
            ->where('public_id', $sessionPublicId)
            ->where('participant_id', $participantId)
            ->first();
        if ($session === null) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        try {
            $instrument = GenericAssessmentInstrument::fromExternal((string) $session->test_type);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        $status = AssessmentSessionStatus::tryFrom((string) $session->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');

        if ($status === AssessmentSessionStatus::Created) {
            return $this->reject('SESSION_NOT_STARTED');
        }

        if ($status !== AssessmentSessionStatus::InProgress) {
            return $this->reject('SESSION_CLOSED');
        }

        if ($session->ends_at === null) {
            throw new RuntimeException('An in-progress session requires ends_at.');
        }
        $endsAt = $this->utc(new DateTimeImmutable((string) $session->ends_at));
        $now = $this->serverTime();
        if ($now > $endsAt) {
            return $this->reject('DEADLINE_EXCEEDED');
        }

        $reference = DB::table('assessment_asset_references')
            ->where('asset_id', $assetId)
            ->where('instrument', $instrument->value)
            ->first();
        if ($reference === null) {
            return $this->reject('ASSET_NOT_FOUND');
        }

        $ttlMinutes = (int) config('assessment_assets.temporary_url_minutes', 10);
        $ttlCappedAt = $now->modify("+{$ttlMinutes} minutes");
        $expiresAt = $ttlCappedAt < $endsAt ? $ttlCappedAt : $endsAt;

        $url = Storage::disk($reference->disk)->temporaryUrl($reference->object_key, $expiresAt);

        return new GetAssessmentSessionAssetUrlResult(true, null, $url, $expiresAt);
    }

    private function reject(string $errorCode): GetAssessmentSessionAssetUrlResult
    {
        return new GetAssessmentSessionAssetUrlResult(false, $errorCode);
    }

    private function serverTime(): DateTimeImmutable
    {
        $time = ($this->clock)();
        if (! $time instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session asset URL clock must return DateTimeImmutable.');
        }

        return $this->utc($time);
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
