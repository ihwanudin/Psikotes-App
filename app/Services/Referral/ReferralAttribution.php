<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Models\Branch;
use App\Models\ReferralVisit;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

final class ReferralAttribution
{
    public function recordVisit(
        string $requestedRefCode,
        ?string $existingCookie,
        ?string $ipAddress,
        ?string $userAgent,
    ): ReferralResolution {
        $now = Carbon::now();
        $existing = $this->decodeCookie($existingCookie, $now);
        $branch = $existing === null
            ? $this->resolveRequestedBranch($requestedRefCode)
            : $this->activeBranch($existing['ref_code']);
        $shouldSetCookie = $existing === null || $branch === null;

        if ($branch === null) {
            $branch = $this->defaultBranch();
        }

        $source = $existing !== null && ! $shouldSetCookie
            ? $existing['source']
            : ($branch->ref_code === $requestedRefCode ? 'link' : 'default');
        $expiresAt = $existing !== null && ! $shouldSetCookie
            ? Carbon::createFromTimestamp($existing['expires_at'])
            : $now->copy()->addDays($this->ttlDays());
        $cookiePayload = $this->encodeCookie($branch, $source, $expiresAt);

        ReferralVisit::query()->create([
            'ref_code' => $requestedRefCode,
            'branch_id' => $branch->id,
            'ip_address' => $ipAddress,
            'user_agent' => $this->minimizeUserAgent($userAgent),
            'first_seen_at' => $now,
            'expires_at' => $expiresAt,
        ]);

        return new ReferralResolution(
            $branch,
            $source,
            $expiresAt,
            $cookiePayload,
            $shouldSetCookie,
        );
    }

    public function assignmentFromCookie(?string $cookie): ReferralAssignment
    {
        $existing = $this->decodeCookie($cookie, Carbon::now());

        if ($existing !== null) {
            $branch = $this->activeBranch($existing['ref_code']);

            if ($branch !== null) {
                return new ReferralAssignment($branch, $existing['source']);
            }
        }

        return new ReferralAssignment($this->defaultBranch(), 'default');
    }

    /** @return array{ref_code: string, source: string, expires_at: int}|null */
    private function decodeCookie(?string $payload, Carbon $now): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ! is_string($decoded['ref_code'] ?? null)
            || ! in_array($decoded['source'] ?? null, ['link', 'default'], true)
            || ! is_int($decoded['expires_at'] ?? null)
            || $decoded['expires_at'] <= $now->getTimestamp()) {
            return null;
        }

        return [
            'ref_code' => $decoded['ref_code'],
            'source' => $decoded['source'],
            'expires_at' => $decoded['expires_at'],
        ];
    }

    private function resolveRequestedBranch(string $refCode): Branch
    {
        return $this->activeBranch($refCode) ?? $this->defaultBranch();
    }

    private function activeBranch(string $refCode): ?Branch
    {
        return Branch::query()
            ->where('ref_code', $refCode)
            ->where('is_active', true)
            ->first();
    }

    private function defaultBranch(): Branch
    {
        return Branch::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first()
            ?? throw new LogicException('An active default branch must be configured.');
    }

    private function encodeCookie(Branch $branch, string $source, Carbon $expiresAt): string
    {
        return json_encode([
            'version' => 1,
            'ref_code' => $branch->ref_code,
            'source' => $source,
            'expires_at' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
    }

    private function minimizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, (int) config('referral.user_agent_max_length', 512));
    }

    private function ttlDays(): int
    {
        return max(1, (int) config('referral.ttl_days', 30));
    }
}
