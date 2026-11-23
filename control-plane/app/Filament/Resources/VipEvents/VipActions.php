<?php

namespace App\Filament\Resources\VipEvents;

use App\Models\VipEvent;
use App\Services\VipPasses;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** Event actions shared by the events table and the event's edit page. */
class VipActions
{
    /** The QR guests scan (print it, put it on a slide). */
    public static function qr(): Action
    {
        return Action::make('qr')
            ->label('QR code')
            ->icon('heroicon-m-qr-code')
            ->color('primary')
            ->modalHeading(fn (VipEvent $record) => 'QR — '.$record->name)
            ->modalDescription('Guests scan this to buy a pass. Print it or show it on screen at the venue.')
            ->modalContent(function (VipEvent $record) {
                $url = $record->publicUrl();
                $img = '<img alt="QR" src="'.(new QRCode)->render($url).'" style="width:260px;height:260px;margin:0 auto 12px;display:block;background:#fff;padding:8px;border-radius:8px">';

                return new HtmlString($img
                    .'<input readonly onclick="this.select()" style="width:100%;font-family:monospace;font-size:13px;padding:8px;border:1px solid #ccc;border-radius:6px" value="'.e($url).'">'
                    .'<p style="margin-top:12px;display:flex;gap:16px;justify-content:center">'
                    .'<a href="'.e(route('vip.event.qr', $record->slug)).'" download="vip-'.e($record->slug).'.svg" style="color:#2563eb;font-weight:600">Download QR (SVG)</a>'
                    .'<a href="'.e($url).'" target="_blank" rel="noopener" style="color:#2563eb;font-weight:600">Open the page</a></p>');
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /** Master switch: every pass of the event off (or back on) at once. */
    public static function toggleAccess(): Action
    {
        return Action::make('toggleAccess')
            ->label(fn (VipEvent $record) => $record->access_enabled ? 'Turn VPN off for all' : 'Turn VPN on for all')
            ->icon(fn (VipEvent $record) => $record->access_enabled ? 'heroicon-m-power' : 'heroicon-m-play-circle')
            ->color(fn (VipEvent $record) => $record->access_enabled ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (VipEvent $record) => $record->access_enabled
                ? 'Every guest of this event loses the VPN within about 15 seconds. You can turn it back on.'
                : 'Every paid pass works again (until the event end time).')
            ->action(function (VipEvent $record) {
                $record->update(['access_enabled' => ! $record->access_enabled]);
                Notification::make()->success()
                    ->title($record->access_enabled ? 'VPN on for every pass' : 'VPN off for every pass')
                    ->send();
            });
    }

    /** Free / cash passes: active at once, the admin hands out the code. */
    public static function give(): Action
    {
        return Action::make('give')
            ->label('Give passes')
            ->icon('heroicon-m-gift')
            ->color('gray')
            ->modalDescription('Passes that need no payment (guests, staff, cash). Each gets its own code; find them under Passes.')
            ->schema([
                TextInput::make('count')->label('How many passes')->integer()->minValue(1)->maxValue(100)->default(1)->required(),
                TextInput::make('devices')->label('Devices per pass')->integer()->minValue(1)->maxValue(50)->default(1)->required(),
                TextInput::make('note')->label('Note (who it is for)')->maxLength(200),
            ])
            ->action(function (array $data, $livewire, $record = null) {
                // Event row / edit page: the record. Passes tab: the tab's event.
                $record = $record instanceof VipEvent ? $record : $livewire->getOwnerRecord();
                $codes = [];
                for ($i = 0; $i < (int) $data['count']; $i++) {
                    $codes[] = app(VipPasses::class)
                        ->give($record, (int) $data['devices'], $data['note'] ?? null, 'admin:'.auth()->id())
                        ->displayCode();
                }
                Notification::make()->success()
                    ->title(count($codes).' pass(es) created')
                    ->body('Code'.(count($codes) > 1 ? 's' : '').': '.implode(', ', array_slice($codes, 0, 10)).(count($codes) > 10 ? ' …' : ''))
                    ->persistent()
                    ->send();
            });
    }
}
