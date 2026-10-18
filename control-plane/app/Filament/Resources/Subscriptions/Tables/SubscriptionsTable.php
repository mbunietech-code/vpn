<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Services\SubscriptionAdmin;
use App\Services\SubscriptionBuilder;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('user.email')
                    ->label('Customer')
                    ->description(fn (Subscription $r) => $r->plan_code.($r->user?->phone ? ' · '.$r->user->phone : ''))
                    ->searchable(),
                TextColumn::make('plan_code')->visibleFrom('md')->label('Plan')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (Subscription $r) => $r->expires_at
                        ? ($r->expires_at->isPast() ? 'ended ' : 'ends ').$r->expires_at->diffForHumans()
                        : null)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('expires_at')->visibleFrom('md')
                    ->label('Expires')
                    ->dateTime('d M Y')
                    ->description(fn (Subscription $r) => $r->expires_at
                        ? $r->expires_at->diffForHumans()
                        : null)
                    ->color(fn (Subscription $r) => $r->expires_at && $r->expires_at->isBefore(now()->addDays(3))
                        ? 'warning' : null)
                    ->sortable(),
                TextColumn::make('devices_count')->visibleFrom('md')->label('Devices')->counts('devices'),
                TextColumn::make('data_used_mb')->visibleFrom('md')->label('Data')
                    ->formatStateUsing(fn ($state) => number_format($state).' MB'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active', 'pending' => 'Pending',
                    'expired' => 'Expired', 'suspended' => 'Suspended',
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('extend')
                        ->label('Add days')
                        ->icon('heroicon-m-calendar-days')
                        ->color('success')
                        ->schema([
                            TextInput::make('days')->label('Days to add')->integer()->minValue(1)->maxValue(3650)->required()->default(30),
                            TextInput::make('note')->label('Note (optional)')->maxLength(200),
                        ])
                        ->action(function (Subscription $r, array $data) {
                            $sub = app(SubscriptionAdmin::class)->extend($r, (int) $data['days'], 'admin:'.auth()->id(), $data['note'] ?? null);
                            Notification::make()->success()->title('Extended')
                                ->body('Now expires '.$sub->expires_at->format('d M Y H:i'))->send();
                        }),
                    Action::make('suspend')
                        ->label('Suspend')
                        ->icon('heroicon-m-pause-circle')
                        ->color('danger')
                        ->visible(fn (Subscription $r) => $r->status === 'active')
                        ->requiresConfirmation()
                        ->modalDescription('The customer is cut off from the VPN within about 15 seconds.')
                        ->schema([TextInput::make('reason')->label('Reason (optional)')->maxLength(200)])
                        ->action(function (Subscription $r, array $data) {
                            app(SubscriptionAdmin::class)->suspend($r, 'admin:'.auth()->id(), $data['reason'] ?? null);
                            Notification::make()->success()->title('Suspended')->send();
                        }),
                    Action::make('restore')
                        ->label('Restore')
                        ->icon('heroicon-m-play-circle')
                        ->color('success')
                        ->visible(fn (Subscription $r) => $r->status === 'suspended')
                        ->requiresConfirmation()
                        ->action(function (Subscription $r) {
                            $sub = app(SubscriptionAdmin::class)->restore($r, 'admin:'.auth()->id());
                            Notification::make()->success()
                                ->title($sub->status === 'active'
                                    ? 'Restored — VPN access is back'
                                    : 'Restored, but the plan has expired — add days to give access')
                                ->send();
                        }),
                    // Support + testing: the /sub link a user would import into a
                    // compatible client. It is a credential - share only with them.
                    Action::make('link')
                        ->label('Subscription link')
                        ->icon('heroicon-m-link')
                        ->color('gray')
                        ->visible(fn (Subscription $r) => $r->isActive())
                        ->modalHeading('Subscription link')
                        ->modalDescription('Copy it into a compatible client (Add profile → from clipboard). This link is private to this customer.')
                        ->modalContent(function (Subscription $r) {
                            $block = function (string $title, string $link): string {
                                // chillerlan/php-qrcode ships with Filament's 2FA deps.
                                $qr = class_exists(QRCode::class)
                                    ? '<img alt="QR" src="'.(new QRCode)->render($link).'" style="width:220px;height:220px;margin:0 auto 12px;display:block;background:#fff;padding:8px;border-radius:8px">'
                                    : '';

                                return '<p style="font-weight:600;margin:0 0 8px">'.e($title).'</p>'.$qr
                                    .'<input readonly onclick="this.select()" style="width:100%;font-family:monospace;font-size:13px;padding:8px;border:1px solid #ccc;border-radius:6px" value="'
                                    .e($link).'">';
                            };
                            $link = url("/sub/{$r->sub_token}");

                            // The iPhone app is not out yet: the official sing-box app
                            // (non-China App Store) imports this as a remote profile.
                            return new HtmlString($block('Other clients (Hiddify, v2rayN)', $link)
                                .'<div style="height:20px"></div>'
                                .$block('iPhone — sing-box app (Profiles → New → Remote)', $link.'?format=singbox&platform=ios&protocol=auto'));
                        })
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    // The customer's own OpenVPN profile, for the OpenVPN Connect
                    // app. It holds their private key: the download is audited
                    // and the contents are never shown on screen.
                    Action::make('ovpn')
                        ->label('OpenVPN profile (.ovpn)')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (Subscription $r) => $r->isActive())
                        ->action(function (Subscription $r) {
                            $profile = app(SubscriptionBuilder::class)->openVpnProfiles($r)[0]['profile'] ?? null;
                            if (! $profile) {
                                Notification::make()->warning()->title('No OpenVPN profile yet')
                                    ->body('The node issues it within a minute of the plan becoming active.')->send();

                                return null;
                            }
                            AuditLog::create([
                                'actor' => 'admin:'.auth()->id(),
                                'action' => 'subscription.ovpn_downloaded',
                                'target' => "subscription:{$r->id}",
                                'meta' => [],
                            ]);

                            return response()->streamDownload(
                                fn () => print $profile,
                                "mbunie-vpn-{$r->id}.ovpn",
                                ['Content-Type' => 'application/x-openvpn-profile'],
                            );
                        }),
                    EditAction::make(),
                    Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete this subscription?')
                        ->modalDescription(fn (Subscription $r) => ($r->isActive() ? 'It is still active: the customer loses VPN access within about 15 seconds. ' : '')
                            .'Its devices and VPN keys are deleted too (removed devices are forgotten). Payments are kept. This cannot be undone.')
                        ->modalSubmitActionLabel('Delete')
                        ->action(function (Subscription $r) {
                            app(SubscriptionAdmin::class)->delete($r, 'admin:'.auth()->id());
                            Notification::make()->success()->title('Subscription deleted')->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete')
                        ->label('Delete selected')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete the selected subscriptions?')
                        ->modalDescription('Active ones lose VPN access within about 15 seconds. Their devices and VPN keys are deleted too; payments are kept. This cannot be undone.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $records->each(fn (Subscription $s) => app(SubscriptionAdmin::class)->delete($s, 'admin:'.auth()->id()));
                            Notification::make()->success()->title('Deleted '.$records->count().' subscription(s)')->send();
                        }),
                ]),
            ]);
    }
}
