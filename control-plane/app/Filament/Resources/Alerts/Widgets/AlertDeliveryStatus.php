<?php

namespace App\Filament\Resources\Alerts\Widgets;

use App\Filament\Pages\Notifications;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Alert;
use App\Services\PaymentAlerts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Top of the Alerts page: is anything watching the network, and where do
 * alerts go? An alert nobody hears about is no alert.
 */
class AlertDeliveryStatus extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $open = Alert::open()->count();
        $critical = Alert::open()->where('severity', 'critical')->count();

        $lastRun = Cache::get('scheduler:last_run');
        $watching = $lastRun && Carbon::parse($lastRun)->gt(now()->subMinutes(5));

        $emails = app(PaymentAlerts::class)->recipients();
        $telegram = filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));

        return [
            Stat::make('Open alerts', (string) $open)
                ->icon('heroicon-o-bell-alert')
                ->description($critical ? "$critical critical" : ($open ? 'No critical ones' : 'All clear'))
                ->descriptionIcon($critical ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($critical ? 'danger' : ($open ? 'warning' : 'success'))
                ->extraAttributes(['class' => 'mvpn-stat '.($critical ? 'mvpn-stat-red' : ($open ? 'mvpn-stat-amber' : 'mvpn-stat-green'))]),

            Stat::make('Network check', $watching ? 'Running' : 'Not running')
                ->icon('heroicon-o-clock')
                ->description($lastRun
                    ? 'Last check '.Carbon::parse($lastRun)->diffForHumans()
                    : 'Add the cron job in hPanel: php artisan schedule:run every minute')
                ->descriptionIcon($watching ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($watching ? 'success' : 'danger')
                ->extraAttributes(['class' => 'mvpn-stat '.($watching ? 'mvpn-stat-green' : 'mvpn-stat-red')]),

            Stat::make('Email alerts', count($emails) ? count($emails).' '.(count($emails) === 1 ? 'inbox' : 'inboxes') : 'Nobody')
                ->icon('heroicon-o-envelope')
                ->description(count($emails) ? implode(', ', array_slice($emails, 0, 2)).(count($emails) > 2 ? '…' : '') : 'Add emails in Setup → Notifications')
                ->color(count($emails) ? 'success' : 'danger')
                ->url(Notifications::getUrl())
                ->extraAttributes(['class' => 'mvpn-stat '.(count($emails) ? 'mvpn-stat-blue' : 'mvpn-stat-red')]),

            Stat::make('Telegram', $telegram ? 'On' : 'Off')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->description($telegram ? 'Critical alerts are posted' : 'Optional: set a bot in Settings & Keys → Alerts')
                ->color($telegram ? 'success' : 'gray')
                ->url(SettingResource::getUrl())
                ->extraAttributes(['class' => 'mvpn-stat '.($telegram ? 'mvpn-stat-teal' : 'mvpn-stat-gray')]),
        ];
    }
}
