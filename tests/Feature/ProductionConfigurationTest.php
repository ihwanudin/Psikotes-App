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

    public function test_trusted_private_edge_proxy_can_report_https_without_overriding_the_public_host(): void
    {
        $response = $this->call('GET', 'http://psikotes.oncam.id/', server: [
            'REMOTE_ADDR' => '172.18.0.1',
            'HTTP_HOST' => 'psikotes.oncam.id',
            'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ]);

        $response->assertOk();
        $response->assertSee('https://psikotes.oncam.id/build/assets/', escape: false);
        $response->assertDontSee('http://psikotes.oncam.id/build/assets/', escape: false);
        $response->assertDontSee('attacker.example', escape: false);
    }

    public function test_untrusted_peer_cannot_spoof_forwarded_scheme_or_host(): void
    {
        $response = $this->call('GET', 'http://psikotes.oncam.id/', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_HOST' => 'psikotes.oncam.id',
            'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ]);

        $response->assertOk();
        $response->assertSee('http://psikotes.oncam.id/build/assets/', escape: false);
        $response->assertDontSee('https://psikotes.oncam.id/build/assets/', escape: false);
        $response->assertDontSee('attacker.example', escape: false);
    }

    public function test_app_config_uses_public_safe_metadata_defaults_without_environment(): void
    {
        $config = $this->loadIsolatedAppConfig([]);

        $this->assertSame('ONCAM Psikotes', $config['name']);
        $this->assertSame('id', $config['locale']);
        $this->assertSame('http://localhost', $config['url']);
    }

    public function test_app_config_honors_explicit_public_metadata_and_url_environment(): void
    {
        $config = $this->loadIsolatedAppConfig([
            'APP_NAME' => 'Configured application name',
            'APP_LOCALE' => 'ja',
            'APP_URL' => 'http://localhost:4321',
        ]);

        $this->assertSame('Configured application name', $config['name']);
        $this->assertSame('ja', $config['locale']);
        $this->assertSame('http://localhost:4321', $config['url']);
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{name: string, locale: string, url: string}
     */
    private function loadIsolatedAppConfig(array $environment): array
    {
        $script = <<<'PHP'
require $argv[1];
$config = require $argv[2];
echo json_encode([
    'name' => $config['name'],
    'locale' => $config['locale'],
    'url' => $config['url'],
], JSON_THROW_ON_ERROR);
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, '--', base_path('vendor/autoload.php'), base_path('config/app.php')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($process)) {
            $this->fail('Unable to start isolated PHP config process.');
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $error ?: 'Isolated config process failed.');
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (
            ! is_array($decoded)
            || ! isset($decoded['name'], $decoded['locale'], $decoded['url'])
            || ! is_string($decoded['name'])
            || ! is_string($decoded['locale'])
            || ! is_string($decoded['url'])
        ) {
            throw new RuntimeException('Isolated app config returned an invalid metadata shape.');
        }

        return [
            'name' => $decoded['name'],
            'locale' => $decoded['locale'],
            'url' => $decoded['url'],
        ];
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
