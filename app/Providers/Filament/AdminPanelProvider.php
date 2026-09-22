<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyRlsContext;
use App\Http\Middleware\RejectDisabledAdmin;
use App\Http\Middleware\RequireMfaForPrivilegedAdmins;
use App\Security\AuditedEmailMultiFactorAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Required for MFA below: Filament's own "set up required" page
            // route only registers when isRequired is true panel-wide
            // (which this design deliberately avoids -- see
            // RequireMfaForPrivilegedAdmins), so the profile page is the
            // only always-registered place an admin can enable MFA from.
            ->profile()
            ->authGuard('admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->multiFactorAuthentication([
                AuditedEmailMultiFactorAuthentication::make()
                    ->codeExpiryMinutes((int) config('admin_accounts.mfa_code_expiry_minutes', 5)),
                // isRequired stays false: Filament's own enforcement cannot
                // be scoped by role (see RequireMfaForPrivilegedAdmins'
                // docblock) -- role-scoped enforcement lives in that
                // middleware below instead. This just registers the
                // provider so any admin can turn it on from their profile.
            ], isRequired: false)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RejectDisabledAdmin::class,
                RequireMfaForPrivilegedAdmins::class,
                ApplyRlsContext::class,
            ], isPersistent: true);
    }
}
