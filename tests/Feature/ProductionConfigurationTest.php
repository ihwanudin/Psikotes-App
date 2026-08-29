<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

final class ProductionConfigurationTest extends TestCase
{
    public function test_production_boot_fails_when_participant_jwt_secret_is_missing(): void
    {
        $this->configureValidProductionRuntime();
        config(['participant_auth.jwt.secret' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PARTICIPANT_JWT_SECRET');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_enabled_selection_integration_requires_secure_complete_configuration(): void
    {
        $this->configureValidProductionRuntime();
        config([
            'selection_integration.enabled' => true,
            'selection_integration.client_secret' => 'too-short',
            'selection_integration.selection_base_url' => 'http://seleksi.example.test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SELECTION_INTEGRATION_CLIENT_SECRET');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_boot_accepts_secure_selection_integration_contract(): void
    {
        $this->configureValidProductionRuntime();
        $this->expectNotToPerformAssertions();

        (new AppServiceProvider($this->app))->boot();
    }

    private function configureValidProductionRuntime(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://psikotes.oncam.id',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.database' => 'psikotes',
            'database.connections.pgsql.username' => 'psikotes_runtime',
            'database.connections.pgsql.password' => 'runtime-password',
            'database.redis.default.password' => 'redis-password',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.driver' => 'redis',
            'session.secure' => true,
            'participant_auth.jwt.secret' => 'base64:'.base64_encode(random_bytes(32)),
            'selection_integration.enabled' => true,
            'selection_integration.client_id' => 'selection-app',
            'selection_integration.client_secret' => str_repeat('S', 32),
            'selection_integration.branch_ref' => 'BEASISWA-JEPANG',
            'selection_integration.test_types' => ['ist'],
            'selection_integration.selection_base_url' => 'https://seleksi.beasiswajepang.id',
            'selection_integration.allow_insecure_local_http' => false,
        ]);
    }
}
