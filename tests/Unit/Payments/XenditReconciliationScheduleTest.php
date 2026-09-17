<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;

final class XenditReconciliationScheduleTest extends TestCase
{
    public function test_status_fallback_is_scheduled_without_overlap(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertIsString($routes);

        $this->assertStringContainsString("Schedule::command('payments:reconcile-xendit')", $routes);
        $this->assertStringContainsString('->everyFiveMinutes()', $routes);
        $this->assertStringContainsString('->withoutOverlapping()', $routes);
        $this->assertStringContainsString('->onOneServer()', $routes);
    }
}
