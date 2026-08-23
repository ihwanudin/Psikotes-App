<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            Redis::command('ping');
        } catch (Throwable) {
            Log::warning('Health dependency check failed.');

            return response()->json(['status' => 'degraded'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
