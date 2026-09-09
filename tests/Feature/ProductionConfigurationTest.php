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

    public function test_enabled_result_callback_requires_exact_directional_secure_configuration(): void
    {
        foreach ([
            ['selection_integration.result_callback_base_url', 'http://seleksi.beasiswajepang.id', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://evil.example', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id/extra', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id:8443', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id?target=other', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://user@seleksi.beasiswajepang.id', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://user:pass@seleksi.beasiswajepang.id', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id#fragment', 'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT'],
            ['selection_integration.result_callback_secret', 'too-short', 'SELECTION_RESULT_CALLBACK_SECRET'],
            ['selection_integration.result_callback_secret', str_repeat('P', 32), 'SELECTION_RESULT_CALLBACK_SECRET'],
            ['selection_integration.result_callback_secret', 'synthetic callback secret with whitespace 123456789', 'SELECTION_RESULT_CALLBACK_SECRET'],
            ['selection_integration.result_callback_secret', str_repeat('S', 32), 'SELECTION_RESULT_CALLBACK_SECRET_DIRECTIONAL'],
            ['selection_integration.result_callback_key_id', 'Invalid Key', 'SELECTION_RESULT_CALLBACK_KEY_ID'],
            ['selection_integration.result_callback_timeout_seconds', 1, 'SELECTION_RESULT_CALLBACK_TIMEOUT'],
            ['selection_integration.result_callback_timeout_seconds', 31, 'SELECTION_RESULT_CALLBACK_TIMEOUT'],
        ] as [$key, $value, $expectedRequirement]) {
            $this->configureValidProductionRuntime();
            config([$key => $value]);

            try {
                (new AppServiceProvider($this->app))->boot();
                $this->fail('Invalid callback production configuration was accepted: '.$key);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expectedRequirement, $exception->getMessage());
            }
        }
    }

    public function test_production_boot_accepts_disabled_or_exact_secure_result_callback_configuration(): void
    {
        $this->configureValidProductionRuntime();
        (new AppServiceProvider($this->app))->boot();
        $this->addToAssertionCount(1);

        config([
            'selection_integration.result_callback_enabled' => false,
            'selection_integration.result_callback_base_url' => null,
            'selection_integration.result_callback_secret' => null,
        ]);
        (new AppServiceProvider($this->app))->boot();
        $this->addToAssertionCount(1);
    }

    public function test_result_callback_environment_contract_is_documented_without_activation(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        $this->assertIsString($example);
        $this->assertStringContainsString('SELECTION_RESULT_CALLBACK_ENABLED=false', $example);
        $this->assertStringContainsString('SELECTION_RESULT_CALLBACK_BASE_URL=https://seleksi.beasiswajepang.id', $example);
        $this->assertStringContainsString('SELECTION_RESULT_CALLBACK_SECRET=', $example);
        $this->assertStringContainsString('SELECTION_RESULT_CALLBACK_KEY_ID=', $example);
        $this->assertStringContainsString('SELECTION_RESULT_CALLBACK_TIMEOUT_SECONDS=10', $example);
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
            'selection_integration.result_callback_enabled' => true,
            'selection_integration.result_callback_base_url' => 'https://seleksi.beasiswajepang.id',
            'selection_integration.result_callback_secret' => 'synthetic-callback-secret-2026-10-ABCDEFGH',
            'selection_integration.result_callback_key_id' => 'psychotest-2026-10',
            'selection_integration.result_callback_timeout_seconds' => 10,
        ]);
    }
}
