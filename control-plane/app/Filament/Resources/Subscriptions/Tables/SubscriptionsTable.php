<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\Subscription;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('user.email')
                    ->description(fn (Subscription $r) => $r->user?->phone)
                    ->searchable(),
                TextColumn::make('plan_code')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($s) => match ($s) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('expires_at')
                    ->label('Inaisha')
                    ->dateTime('d M Y')
                    ->description(fn (Subscription $r) => $r->expires_at
                        ? $r->expires_at->diffForHumans()
                        : null)
                    ->color(fn (Subscription $r) => $r->expires_at && $r->expires_at->isBefore(now()->addDays(3))
                        ? 'warning' : null)
                    ->sortable(),
                TextColumn::make('devices_count')->label('Vifaa')->counts('devices'),
                TextColumn::make('data_used_mb')->label('Data')
                    ->formatStateUsing(fn ($s) => number_format($s) . ' MB'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active', 'pending' => 'Pending',
                    'expired' => 'Expired', 'suspended' => 'Suspended',
                ]),
            ])
            ->recordActions([
                // Support + testing: the /sub link a user would import into
                // Hiddify / sing-box. It is a credential - share only with them.
                Action::make('link')
                    ->label('Link')
                    ->icon('heroicon-m-link')
                    ->color('gray')
                    ->visible(fn (Subscription $r) => $r->isActive())
                    ->modalHeading('Subscription link')
                    ->modalDescription('Nakili na uiimport kwenye Hiddify (Add profile → clipboard). Ni siri ya mtumiaji huyu.')
                    ->modalContent(function (Subscription $r) {
                        $link = url("/sub/{$r->sub_token}");
                        // chillerlan/php-qrcode ships with Filament's 2FA deps.
                        $qr = class_exists(\chillerlan\QRCode\QRCode::class)
                            ? '<img alt="QR" src="' . (new \chillerlan\QRCode\QRCode)->render($link) . '" style="width:220px;height:220px;margin:0 auto 12px;display:block;background:#fff;padding:8px;border-radius:8px">'
                            : '';

                        return new HtmlString($qr
                            . '<input readonly onclick="this.select()" style="width:100%;font-family:monospace;font-size:13px;padding:8px;border:1px solid #ccc;border-radius:6px" value="'
                            . e($link) . '">');
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Funga'),
                EditAction::make(),
            ]);
    }
}
