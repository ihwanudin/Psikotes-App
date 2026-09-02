<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Integrations\CheckoutSessionHttpContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProtectCheckoutSessionHttpBoundary
{
    public function __construct(private CheckoutSessionHttpContract $contract) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contract->enabled() || ! $this->contract->destinationMatches($request)) {
            return $this->contract->private(response('Not Found', 404));
        }
        if ($this->contract->limit($request) === null) {
            return $this->contract->private(response('Not Found', 404));
        }

        return $this->contract->private($next($request));
    }
}
