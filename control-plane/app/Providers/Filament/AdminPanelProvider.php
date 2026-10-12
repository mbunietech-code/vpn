<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\OpenAlerts;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PendingApprovals;
use App\Filament\Widgets\RevenueChart;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
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
            ->brandName('Mbunie VPN')
            ->brandLogo(asset('images/mvpn-logo-light.svg').'?v=4')
            ->darkModeBrandLogo(asset('images/mvpn-logo-dark.svg').'?v=4')
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/favicon-64.png').'?v=4')
            ->login()
            // Brand look for the sign-in page (no theme build needed).
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => <<<'HTML'
                <style>
                    .fi-simple-layout {
                        background:
                            radial-gradient(900px 480px at 50% -8%, rgba(37, 99, 235, .16), transparent 62%),
                            linear-gradient(180deg, #f6f8fc 0%, #eaf0fa 100%);
                    }
                    .dark .fi-simple-layout {
                        background:
                            radial-gradient(900px 480px at 50% -8%, rgba(45, 212, 191, .14), transparent 62%),
                            #0a1d2e;
                    }
                    .fi-simple-main {
                        border-radius: 1rem;
                        box-shadow: 0 24px 60px -24px rgba(10, 29, 46, .35);
                    }
                    /* Tables: drop the empty toolbar-actions row (it left a blank
                       gap above the search box on phones). */
                    .fi-ta-header-toolbar .fi-ta-actions:not(:has(*)) {
                        display: none;
                    }
                    /* All screens: the first column (customer/name) and second
                       lines wrap, so a long email never pushes the table
                       sideways next to the sidebar on tablets and laptops. */
                    .fi-ta-cell .fi-ta-text-description { white-space: normal; }
                    .fi-ta-row .fi-ta-cell:first-child { max-width: 18rem; }
                    .fi-ta-row .fi-ta-cell:first-child .fi-ta-col,
                    .fi-ta-row .fi-ta-cell:first-child .fi-ta-text,
                    .fi-ta-row .fi-ta-cell:first-child .fi-ta-text-item,
                    .fi-ta-row .fi-ta-cell:first-child .fi-ta-text-item-label,
                    .fi-ta-row .fi-ta-cell:first-child .fi-ta-text-description {
                        white-space: normal;
                        overflow-wrap: anywhere;
                    }
                    /* Phones: let long emails/names wrap instead of pushing the
                       table sideways. */
                    @media (max-width: 640px) {
                        .fi-ta-cell { padding-left: .6rem; padding-right: .35rem; }
                        .fi-ta-cell:last-child { padding-right: .5rem; }
                        /* Secondary lines under a cell (e.g. "seen 2 min ago") may wrap. */
                        .fi-ta-cell .fi-ta-col,
                        .fi-ta-cell .fi-ta-text,
                        .fi-ta-cell .fi-ta-text-description { white-space: normal; }
                        /* Only the first column (customer/name) wraps, with a
                           readable minimum width; numbers and dates stay intact. */
                        .fi-ta-row .fi-ta-cell:first-child { min-width: 8.5rem; max-width: 11.5rem; }
                        .fi-ta-row .fi-ta-cell:first-child .fi-ta-col,
                        .fi-ta-row .fi-ta-cell:first-child .fi-ta-text,
                        .fi-ta-row .fi-ta-cell:first-child .fi-ta-text-item,
                        .fi-ta-row .fi-ta-cell:first-child .fi-ta-text-item-label,
                        .fi-ta-row .fi-ta-cell:first-child .fi-ta-text-description {
                            white-space: normal;
                            overflow-wrap: anywhere;
                        }
                    }
                    /* Very small phones (≤ 359px): tighter still. */
                    @media (max-width: 359px) {
                        .fi-ta-cell { padding-left: .45rem; padding-right: .25rem; }
                        .fi-ta-row .fi-ta-cell:first-child { min-width: 7rem; }
                        .fi-ta-cell .fi-ta-text-description { font-size: .72rem; }
                    }
                </style>
                HTML)
            ->colors([
                'primary' => Color::hex('#2563EB'),
            ])
            ->font('Inter')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Infrastructure'),
                NavigationGroup::make('Configuration'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                OverviewStats::class,
                PendingApprovals::class,
                OpenAlerts::class,
                RevenueChart::class,
                AccountWidget::class,
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
