<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
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
}
