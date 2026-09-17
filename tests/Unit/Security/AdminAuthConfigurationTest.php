<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Models\Admin;
use PHPUnit\Framework\TestCase;

final class AdminAuthConfigurationTest extends TestCase
{
    public function test_admin_session_guard_uses_admin_model(): void
    {
        $config = require dirname(__DIR__, 3).'/config/auth.php';

        $this->assertSame('session', $config['guards']['admin']['driver']);
        $this->assertSame('admins', $config['guards']['admin']['provider']);
        $this->assertSame('eloquent', $config['providers']['admins']['driver']);
        $this->assertSame(Admin::class, $config['providers']['admins']['model']);
    }

    public function test_session_cookie_defaults_are_secure_and_expiring(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/config/session.php');
        $this->assertIsString($contents);

        $this->assertStringContainsString("env('SESSION_SECURE_COOKIE', true)", $contents);
        $this->assertStringContainsString("env('SESSION_HTTP_ONLY', true)", $contents);
        $this->assertStringContainsString("env('SESSION_SAME_SITE', 'lax')", $contents);
        $this->assertStringContainsString("env('SESSION_LIFETIME', 120)", $contents);
    }
}
