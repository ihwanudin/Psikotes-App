<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Services\ParticipantAuth\Exceptions\ParticipantCredentialLocked;
use Illuminate\Support\Facades\RateLimiter;

final class ParticipantLoginThrottle
{
    public function ensureNotLocked(string $testNumber): void
    {
        $key = $this->lockKey($testNumber);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw new ParticipantCredentialLocked(max(1, RateLimiter::availableIn($key)));
        }
    }

    public function recordFailure(string $testNumber): void
    {
        $attempts = RateLimiter::hit(
            $this->failureKey($testNumber),
            (int) config('participant_auth.login_lockout.failure_memory_seconds', 86_400),
        );
        $firstLock = (int) config('participant_auth.login_lockout.first_lock_attempt', 3);

        if ($attempts >= $firstLock) {
            RateLimiter::clear($this->lockKey($testNumber));
            RateLimiter::hit($this->lockKey($testNumber), $this->lockSeconds($attempts, $firstLock));
        }
    }

    public function clear(string $testNumber): void
    {
        RateLimiter::clear($this->failureKey($testNumber));
        RateLimiter::clear($this->lockKey($testNumber));
    }

    private function lockSeconds(int $attempts, int $firstLock): int
    {
        return match ($attempts - $firstLock) {
            0 => 60,
            1 => 5 * 60,
            default => 15 * 60,
        };
    }

    private function failureKey(string $testNumber): string
    {
        return 'participant-login:failures:'.$this->fingerprint($testNumber);
    }

    private function lockKey(string $testNumber): string
    {
        return 'participant-login:lock:'.$this->fingerprint($testNumber);
    }

    private function fingerprint(string $testNumber): string
    {
        return hash_hmac('sha256', $testNumber, (string) config('app.key'));
    }
}
