<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Contracts\ProvidesRlsContext;
use App\Contracts\RunsRlsContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ApplyRlsContextToJob
{
    public function __construct(private ?RunsRlsContext $runner = null) {}

    /**
     * @param  callable(object): void  $next
     */
    public function handle(object $job, callable $next): void
    {
        if (! $job instanceof ProvidesRlsContext) {
            throw new AuthorizationException('A queued tenant job must provide an RLS context.');
        }

        $runner = $this->runner ?? app(RunsRlsContext::class);

        $runner->run(
            $job->rlsContext(),
            static function () use ($job, $next): void {
                $next($job);
            },
        );
    }
}
