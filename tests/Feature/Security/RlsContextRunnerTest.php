<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class RlsContextRunnerTest extends TestCase
{
    public function test_context_is_only_active_inside_callback(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $context = new RlsContext('branch_admin', 10);

        $this->assertNull($runner->current());

        $result = $runner->run($context, function () use ($runner, $context): string {
            $this->assertSame($context, $runner->current());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertNull($runner->current());
    }

    public function test_context_is_cleared_when_callback_throws(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);

        try {
            $runner->run(new RlsContext('participant', 10, 20), static function (): never {
                throw new RuntimeException('expected');
            });

            $this->fail('The callback should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected', $exception->getMessage());
        }

        $this->assertNull($runner->current());
    }

    public function test_sequential_tenants_do_not_share_process_context(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $seen = [];

        foreach ([11, 22] as $branchId) {
            $runner->run(new RlsContext('branch_admin', $branchId), function () use ($runner, &$seen): void {
                $seen[] = $runner->current()?->branchId;
            });

            $this->assertNull($runner->current());
        }

        $this->assertSame([11, 22], $seen);
    }

    public function test_admin_context_can_temporarily_elevate_to_service_and_is_restored(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $admin = new RlsContext('branch_admin', 10);

        $runner->run($admin, function () use ($runner, $admin): void {
            $result = $runner->runAsService(function () use ($runner): string {
                $this->assertSame('service', $runner->current()?->role);

                return 'elevated';
            });

            $this->assertSame('elevated', $result);
            $this->assertSame($admin, $runner->current());
        });

        $this->assertNull($runner->current());
    }

    public function test_service_elevation_restores_admin_context_after_an_exception(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $admin = new RlsContext('staff', 10);

        $runner->run($admin, function () use ($runner, $admin): void {
            try {
                $runner->runAsService(static function (): never {
                    throw new RuntimeException('expected');
                });

                $this->fail('The elevated callback should have thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('expected', $exception->getMessage());
            }

            $this->assertSame($admin, $runner->current());
        });
    }

    public function test_participant_context_cannot_elevate_to_service(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);

        $this->expectException(LogicException::class);

        $runner->run(
            new RlsContext('participant', 10, 20),
            fn () => $runner->runAsService(static fn (): null => null),
        );
    }
}
