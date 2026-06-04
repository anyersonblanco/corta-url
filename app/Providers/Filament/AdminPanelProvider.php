<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use App\Filament\Widgets\LinkStatsWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->favicon(asset('favicon.ico'))
            ->brandName('CortarLink · Webtilia')
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('2.5rem')
            // Paleta Webtilia (Manual de Marca): azul #0066FF primary, amarillo
            // #FFD700 como warning/acento. Filament 4 deriva la escala 50-950
            // automáticamente desde el hex.
            ->colors([
                'primary' => Color::hex('#0066FF'),
                'warning' => Color::hex('#FFD700'),
            ])
            // Dark mode off: la paleta corporativa pierde contraste en dark.
            // El cliente final ve siempre azul + amarillo Webtilia consistente.
            ->darkMode(false)
            // BODY_END (no FOOTER) — Filament 4 renderiza FOOTER solo en el layout
            // "app" (post-login). BODY_END es universal: login + app.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.admin.footer')->render()
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                LinkStatsWidget::class,
            ])
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
            ]);
    }
}
