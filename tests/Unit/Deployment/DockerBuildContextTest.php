<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class DockerBuildContextTest extends TestCase
{
    public function test_local_bootstrap_package_cache_is_excluded_from_the_production_image(): void
    {
        $dockerignore = file_get_contents(dirname(__DIR__, 3).'/.dockerignore');

        $this->assertIsString($dockerignore);
        $this->assertStringContainsString('bootstrap/cache/*.php', $dockerignore);
    }
}
