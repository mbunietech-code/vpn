<?php

namespace App\Filament\Resources\Alerts\Pages;

use App\Filament\Resources\Alerts\AlertResource;
use App\Filament\Resources\Alerts\Widgets\AlertDeliveryStatus;
use App\Models\Alert;
use App\Services\AlertAnalyzer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListAlerts extends ListRecords
{
    protected static string $resource = AlertResource::class;

    protected ?string $subheading = 'Problems on the VPN nodes and with payments. New warnings and critical alerts are emailed to the admins.';

    protected function getHeaderWidgets(): array
    {
        return [AlertDeliveryStatus::class];
    }

    public function getTabs(): array
    {
        $open = Alert::open()->count();
        $critical = Alert::open()->where('severity', 'critical')->count();

        return [
            'open' => Tab::make('Open')
                ->icon(Heroicon::OutlinedBellAlert)
                ->badge($open ?: null)
                ->badgeColor($critical ? 'danger' : 'warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('acknowledged_at')),
            'critical' => Tab::make('Critical')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->badge($critical ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('acknowledged_at')->where('severity', 'critical')),
            'resolved' => Tab::make('Resolved')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('acknowledged_at')),
            'all' => Tab::make('All')
                ->icon(Heroicon::OutlinedListBullet),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test alert')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send a test alert?')
                ->modalDescription('A critical test alert is sent the way a real one would be: email to the admins and Telegram if it is set. Nothing is saved.')
                ->action(fn () => $this->sendTest()),

            Action::make('resolveAll')
                ->label('Resolve all open')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn () => Alert::open()->exists())
                ->requiresConfirmation()
                ->modalDescription('Marks every open alert as resolved. A problem that is still there is raised again on the next check.')
                ->action(function () {
                    $n = Alert::open()->update(['acknowledged_at' => now(), 'acknowledged_by' => 'admin:'.auth()->id()]);
                    Notification::make()->success()->title("$n ".($n === 1 ? 'alert' : 'alerts').' resolved')->send();
                }),
        ];
    }

    private function sendTest(): void
    {
        // Not saved: built in memory and sent like a real one.
        $alert = new Alert([
            'severity' => 'critical',
            'source' => 'test',
            'title' => 'Test alert from the admin panel',
            'body' => 'If you can read this, Mbunie VPN alerts reach you.',
        ]);
        $alert->created_at = now();

        $r = app(AlertAnalyzer::class)->notify($alert);

        $where = [];
        if ($r['email']) {
            $where[] = 'email to '.implode(', ', $r['email']);
        }
        if ($r['telegram'] === true) {
            $where[] = 'Telegram';
        }

        $problems = [];
        if ($r['failed']) {
            $problems[] = 'email failed for '.implode(', ', $r['failed']).' (check Settings & Keys → Email)';
        }
        if ($r['telegram'] === false) {
            $problems[] = 'Telegram failed (check the bot token and chat ID)';
        }

        Notification::make()
            ->{$problems ? 'warning' : ($where ? 'success' : 'danger')}()
            ->title($where ? 'Test alert sent: '.implode(' and ', $where) : 'The test alert went nowhere')
            ->body($problems ? ucfirst(implode('; ', $problems)).'.' : ($where ? 'Check the inbox (and Spam).' : 'Add an email in Setup → Notifications.'))
            ->persistent()
            ->send();
    }
}
