<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;
use Throwable;

final class ForkedProcessResult
{
    /** @param array<string, mixed> $result */
    public static function sendOrExitFailure(mixed $stream, array $result, ?Closure $cleanup = null): void
    {
        try {
            if (! is_resource($stream)
                || fwrite($stream, json_encode($result, JSON_THROW_ON_ERROR)."\n") === false) {
                throw new RuntimeException('Forked test result channel is unavailable.');
            }
        } catch (Throwable) {
            self::exitAfterCleanup($stream, $cleanup, 2);
        }
    }

    /** @param array<string, mixed> $result */
    public static function sendAndExit(mixed $stream, array $result, ?Closure $cleanup = null): void
    {
        self::sendOrExitFailure($stream, $result, $cleanup);
        self::exitAfterCleanup($stream, $cleanup, 0);
    }

    private static function exitAfterCleanup(mixed $stream, ?Closure $cleanup, int $exitCode): never
    {
        try {
            $cleanup?->__invoke();
        } catch (Throwable) {
            $exitCode = 2;
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        exit($exitCode);
    }
}
