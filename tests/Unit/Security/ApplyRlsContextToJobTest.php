<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Contracts\ProvidesRlsContext;
use App\Contracts\RunsRlsContext;
use App\Jobs\Middleware\ApplyRlsContextToJob;
use App\Security\RlsContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\TestCase;

final class ApplyRlsContextToJobTest extends TestCase
{
    public function test_job_without_context_contract_is_rejected(): void
    {
        $runner = $this->createMock(RunsRlsContext::class);
        $middleware = new ApplyRlsContextToJob($runner);

        $this->expectException(AuthorizationException::class);

        $middleware->handle(new \stdClass, static function (): void {});
    }

    public function test_job_context_wraps_execution(): void
    {
        $context = new RlsContext('participant', 3, 9);
        $job = new class($context) implements ProvidesRlsContext
        {
            public function __construct(private readonly RlsContext $context) {}

            public function rlsContext(): RlsContext
            {
                return $this->context;
            }
        };
        $runner = $this->createMock(RunsRlsContext::class);
        $runner->expects($this->once())
            ->method('run')
            ->with($context, $this->isType('callable'))
            ->willReturnCallback(static fn (RlsContext $unused, callable $callback): mixed => $callback());
        $middleware = new ApplyRlsContextToJob($runner);
        $handled = false;

        $middleware->handle($job, static function () use (&$handled): void {
            $handled = true;
        });

        $this->assertTrue($handled);
    }
}
