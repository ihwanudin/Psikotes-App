<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;

final class XenditWebhookConfigurationTest extends TestCase
{
    public function test_only_the_xendit_webhook_path_is_excluded_from_request_forgery_protection(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 3).'/bootstrap/app.php');
        $this->assertIsString($bootstrap);

        $this->assertStringContainsString(
            "preventRequestForgery(except: ['webhooks/xendit'])",
            $bootstrap,
        );
        $this->assertStringNotContainsString("preventRequestForgery(except: ['*'])", $bootstrap);
    }
}
