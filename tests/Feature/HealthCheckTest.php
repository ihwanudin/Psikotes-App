<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_ready_when_database_and_redis_are_available(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([(object) ['one' => 1]]);
        Redis::shouldReceive('command')->once()->with('ping')->andReturn('PONG');

        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_endpoint_fails_closed_without_exposing_dependency_errors(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andThrow(new RuntimeException('database-password-leak'));
        Redis::shouldReceive('command')->never();

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded'])
            ->assertDontSee('database-password-leak');
    }

    public function test_health_endpoint_fails_closed_without_stateful_session_middleware_or_json_accept_header(): void
    {
        config()->set('database.default', 'health_dependency_down');
        config()->set('database.connections.health_dependency_down', [
            'driver' => 'sqlite',
            'database' => database_path('missing-f9-o1-health-check/health.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('session.driver', 'database');
        config()->set('session.connection', 'health_dependency_down');

        $response = $this->get('/health');

        $response
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded']);
        $this->assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('content-type')
        );
    }
}
