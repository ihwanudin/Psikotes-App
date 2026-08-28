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

    public function test_frontend_image_reuses_pre_generated_wayfinder_types_without_php(): void
    {
        $root = dirname(__DIR__, 3);
        $dockerfile = file_get_contents($root.'/docker/app/Dockerfile');
        $viteConfig = file_get_contents($root.'/vite.config.ts');

        $this->assertIsString($dockerfile);
        $this->assertIsString($viteConfig);
        $this->assertStringContainsString('ENV WAYFINDER_COMMAND=true', $dockerfile);
        $this->assertStringContainsString('process.env.WAYFINDER_COMMAND', $viteConfig);
        $this->assertStringContainsString("'php artisan wayfinder:generate'", $viteConfig);
    }
}
