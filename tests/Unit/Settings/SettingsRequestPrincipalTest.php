<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\Admin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SettingsRequestPrincipalTest extends TestCase
{
    /** @param class-string<FormRequest> $requestClass */
    #[DataProvider('webUserRequests')]
    public function test_settings_request_rejects_an_admin_guard_principal(string $requestClass): void
    {
        $request = new $requestClass;
        $request->setUserResolver(static fn (): Admin => new Admin);

        $this->expectException(AuthorizationException::class);

        $request->user();
    }

    /** @return iterable<string, array{class-string<FormRequest>}> */
    public static function webUserRequests(): iterable
    {
        yield 'profile update' => [ProfileUpdateRequest::class];
        yield 'two-factor settings' => [TwoFactorAuthenticationRequest::class];
    }
}
