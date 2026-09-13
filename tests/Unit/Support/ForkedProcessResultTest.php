<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ForkedProcessResult;
use Throwable;

final class ForkedProcessResultTest extends TestCase
{
    public function test_closed_result_channel_cannot_return_control_to_inherited_test_runner(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($pair);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($pair[0]);
            fclose($pair[1]);

            try {
                ForkedProcessResult::sendAndExit($pair[1], ['ok' => true]);
            } catch (Throwable) {
                exit(9);
            }

            exit(8);
        }

        fclose($pair[0]);
        fclose($pair[1]);
        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(2, pcntl_wexitstatus($status));
    }

    public function test_closed_intermediate_channel_terminates_before_follow_up_barrier(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($pair);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($pair[0]);
            fclose($pair[1]);

            try {
                ForkedProcessResult::sendOrExitFailure($pair[1], ['ready' => true]);
            } catch (Throwable) {
                exit(9);
            }

            exit(8);
        }

        fclose($pair[0]);
        fclose($pair[1]);
        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(2, pcntl_wexitstatus($status));
    }

    public function test_zero_byte_write_fails_closed_without_returning_to_the_inherited_runner(): void
    {
        $this->assertChildExitCode(2, static function (): void {
            $stream = self::openWritableStream();
            AdversarialResultStream::configure($stream, 'zero');

            ForkedProcessResult::sendOrExitFailure($stream, ['ok' => true]);
        });
    }

    public function test_short_write_is_completed_before_successful_cleanup_and_exit(): void
    {
        $result = ['ok' => true];
        AdversarialResultStream::$expectedPayload = json_encode($result, JSON_THROW_ON_ERROR)."\n";

        $this->assertChildExitCode(0, static function () use ($result): void {
            $stream = self::openWritableStream();
            AdversarialResultStream::configure($stream, 'short-then-complete');

            ForkedProcessResult::sendAndExit($stream, $result);
        });
    }

    public function test_more_than_sixteen_one_byte_writes_complete_before_successful_exit(): void
    {
        $result = ['payload' => str_repeat('x', 64)];
        AdversarialResultStream::$expectedPayload = json_encode($result, JSON_THROW_ON_ERROR)."\n";

        $this->assertChildExitCode(0, static function () use ($result): void {
            $stream = self::openWritableStream();
            AdversarialResultStream::configure($stream, 'one-byte-chunks');

            ForkedProcessResult::sendAndExit($stream, $result);
        });
    }

    public function test_short_write_followed_by_no_progress_fails_closed(): void
    {
        $this->assertChildExitCode(2, static function (): void {
            $stream = self::openWritableStream();
            AdversarialResultStream::configure($stream, 'short-then-zero');

            ForkedProcessResult::sendOrExitFailure($stream, ['ok' => true]);
        });
    }

    public function test_terminal_close_throwable_fails_closed_without_returning_to_the_inherited_runner(): void
    {
        $this->assertChildExitCode(2, static function (): void {
            $stream = self::openWritableStream();
            AdversarialResultStream::configure($stream, 'close-throws');

            ForkedProcessResult::sendAndExit($stream, ['ok' => true]);
        });
    }

    public function test_successful_send_runs_cleanup_before_close_and_exits_zero(): void
    {
        $this->assertChildExitCode(0, static function (): void {
            $stream = self::openWritableStream();
            AdversarialResultStream::configure($stream, 'require-cleanup');

            ForkedProcessResult::sendAndExit($stream, ['ok' => true], static function (): void {
                AdversarialResultStream::$cleanupRan = true;
            });
        });
    }

    public function test_cleanup_throwable_forces_failure_exit_without_returning_to_the_inherited_runner(): void
    {
        $this->assertChildExitCode(2, static function (): void {
            $stream = self::openWritableStream();

            ForkedProcessResult::sendAndExit($stream, ['ok' => true], static function (): void {
                throw new RuntimeException('Synthetic cleanup failure.');
            });
        });
    }

    private function assertChildExitCode(int $expectedExitCode, Closure $operation): void
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            try {
                $operation();
            } catch (Throwable) {
                exit(9);
            }

            exit(8);
        }

        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame($expectedExitCode, pcntl_wexitstatus($status));
    }

    /** @return resource */
    private static function openWritableStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the synthetic result stream.');
        }

        return $stream;
    }
}

final class AdversarialResultStream
{
    public static string $expectedPayload = '';

    public static bool $cleanupRan = false;

    /** @var array<int, string> */
    private static array $modes = [];

    /** @var array<int, string> */
    private static array $written = [];

    /** @param resource $stream */
    public static function configure(mixed $stream, string $mode): void
    {
        $id = get_resource_id($stream);
        self::$modes[$id] = $mode;
        self::$written[$id] = '';
    }

    /** @param resource $stream */
    public static function write(mixed $stream, string $data): int|false
    {
        $id = get_resource_id($stream);
        $mode = self::$modes[$id] ?? null;

        if ($mode === null) {
            return \fwrite($stream, $data);
        }

        if ($mode === 'zero') {
            return 0;
        }

        if ($mode === 'short-then-zero' && self::$written[$id] !== '') {
            return 0;
        }

        if (in_array($mode, ['one-byte-chunks', 'short-then-zero'], true)
            || ($mode === 'short-then-complete' && self::$written[$id] === '')) {
            self::$written[$id] .= $data[0];

            return 1;
        }

        self::$written[$id] .= $data;

        return strlen($data);
    }

    /** @param resource $stream */
    public static function close(mixed $stream): bool
    {
        $id = get_resource_id($stream);
        $mode = self::$modes[$id] ?? null;

        if ($mode === 'close-throws') {
            throw new RuntimeException('Synthetic close failure.');
        }

        if (in_array($mode, ['one-byte-chunks', 'short-then-complete'], true)
            && self::$written[$id] !== self::$expectedPayload) {
            throw new RuntimeException('Synthetic incomplete payload failure.');
        }

        if ($mode === 'require-cleanup' && ! self::$cleanupRan) {
            throw new RuntimeException('Synthetic missing cleanup failure.');
        }

        unset(self::$modes[$id], self::$written[$id]);

        return \fclose($stream);
    }
}

namespace Tests\Support;

use Tests\Unit\Support\AdversarialResultStream;

/** @param resource $stream */
function fwrite(mixed $stream, string $data): int|false
{
    return AdversarialResultStream::write($stream, $data);
}

/** @param resource $stream */
function fclose(mixed $stream): bool
{
    return AdversarialResultStream::close($stream);
}
