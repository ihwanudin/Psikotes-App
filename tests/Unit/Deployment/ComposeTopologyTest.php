<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ComposeTopologyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $compose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compose = Yaml::parseFile(dirname(__DIR__, 3).'/compose.yaml');
    }

    public function test_required_services_share_one_application_image(): void
    {
        $services = $this->compose['services'];

        foreach (['app', 'queue', 'scheduler', 'postgres', 'redis'] as $service) {
            $this->assertArrayHasKey($service, $services);
        }

        $this->assertSame($services['app']['image'], $services['queue']['image']);
        $this->assertSame($services['app']['image'], $services['scheduler']['image']);
        $this->assertSame($services['app']['build'], $services['queue']['build']);
        $this->assertSame($services['app']['build'], $services['scheduler']['build']);
    }

    #[DataProvider('privateDependencyProvider')]
    public function test_data_services_are_private_and_health_checked(string $service): void
    {
        $definition = $this->compose['services'][$service];

        $this->assertArrayNotHasKey('ports', $definition);
        $this->assertSame(['backend'], $definition['networks']);
        $this->assertArrayHasKey('healthcheck', $definition);
    }

    public function test_backend_network_is_internal_and_state_uses_named_volumes(): void
    {
        $this->assertTrue($this->compose['networks']['backend']['internal']);

        foreach (['postgres-data', 'redis-data', 'app-storage'] as $volume) {
            $this->assertArrayHasKey($volume, $this->compose['volumes']);
        }
    }

    public function test_notification_queue_and_provider_configuration_reach_workers(): void
    {
        $queue = $this->compose['services']['queue'];
        $command = $queue['command'];
        $environment = $queue['environment'];

        $this->assertContains('--queue=notifications,default', $command);
        $this->assertContains('--tries=5', $command);
        $this->assertContains('--timeout=120', $command);
        $this->assertSame(['edge', 'backend'], $queue['networks']);
        $this->assertSame('${N8N_WEBHOOK_URL:-}', $environment['N8N_WEBHOOK_URL']);
        $this->assertSame('${N8N_WEBHOOK_TOKEN:-}', $environment['N8N_WEBHOOK_TOKEN']);
        $this->assertSame('${N8N_ALLOW_INSECURE_LOCAL_HTTP:-false}', $environment['N8N_ALLOW_INSECURE_LOCAL_HTTP']);
        $this->assertSame('${REDIS_QUEUE_RETRY_AFTER:-150}', $environment['REDIS_QUEUE_RETRY_AFTER']);
    }

    /** @return iterable<string, array{string}> */
    public static function privateDependencyProvider(): iterable
    {
        yield 'PostgreSQL' => ['postgres'];
        yield 'Redis' => ['redis'];
    }
}
