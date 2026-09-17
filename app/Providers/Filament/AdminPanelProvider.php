<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\EnsurePrivilegedUsersHaveTwoFactor;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->brandName('Shopify Product Studio')
            /*
             * Segundo factor obligatorio para administradores técnicos (RFC-0001).
             * Se exige también a cualquier usuario que ya lo tenga configurado,
             * para que nadie pueda desactivarlo desde su perfil y bajar el nivel.
             */
            /*
             * Se ofrece TOTP con códigos de recuperación a cualquier usuario.
             * La obligación para administradores técnicos no se declara aquí
             * porque `isRequired` se evalúa al registrar rutas, sin usuario:
             * se aplica en tiempo de petición con EnsurePrivilegedUsersHaveTwoFactor.
             */
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePrivilegedUsersHaveTwoFactor::class,
            ]);
    }
}
