<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ExpiringSoon;
use App\Filament\Widgets\OpenAlerts;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PendingApprovals;
use App\Filament\Widgets\PlanMixChart;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\SalesStats;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
            // Brand look (plain stylesheet: no theme build on Hostinger).
            ->renderHook(PanelsRenderHook::HEAD_END, function (): string {
                $file = public_path('css/mvpn-admin.css');
                $version = is_file($file) ? filemtime($file) : 1;

                return '<link rel="stylesheet" href="'.e(asset('css/mvpn-admin.css')).'?v='.$version.'">';
            })
            ->colors([
                // Tailwind blue: its 600 is the brand #2563EB, so buttons get solid brand blue.
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Network'),
                NavigationGroup::make('Setup'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                SalesStats::class,
                PendingApprovals::class,
                RevenueChart::class,
                PlanMixChart::class,
                ExpiringSoon::class,
                OverviewStats::class,
                OpenAlerts::class,
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
