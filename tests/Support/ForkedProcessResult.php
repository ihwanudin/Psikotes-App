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
            if (! is_resource($stream)) {
                throw new RuntimeException('Forked test result channel is unavailable.');
            }

            $payload = json_encode($result, JSON_THROW_ON_ERROR)."\n";
            $payloadLength = strlen($payload);
            $writtenLength = 0;

            for ($attempt = 0; $attempt < $payloadLength && $writtenLength < $payloadLength; $attempt++) {
                $remainingPayload = substr($payload, $writtenLength);
                $written = fwrite($stream, $remainingPayload);

                if ($written === false || $written <= 0 || $written > strlen($remainingPayload)) {
                    throw new RuntimeException('Forked test result channel is unavailable.');
                }

                $writtenLength += $written;
            }

            if ($writtenLength !== $payloadLength) {
                throw new RuntimeException('Forked test result channel did not accept the complete payload.');
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

        try {
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable) {
            $exitCode = 2;
        }

        exit($exitCode);
    }
}
