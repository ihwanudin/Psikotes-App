<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
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
}
