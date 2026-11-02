<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Customers whose plan ends this week: the people to remind about renewing. */
class ExpiringSoon extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected static ?string $heading = 'Renewals due in the next 7 days';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::query()->exists();
    }

    protected static function query()
    {
        return Subscription::query()
            ->with('user')
            ->withCount(['devices' => fn ($q) => $q->whereNull('revoked_at')])
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->orderBy('expires_at');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::query())
            ->columns([
                // OTP customers often have no name: fall back to email / phone.
                TextColumn::make('customer')
                    ->label('Customer')
                    ->state(fn (Subscription $r) => $r->user?->name ?: ($r->user?->email ?: ($r->user?->phone ?: '#'.$r->user_id)))
                    ->description(fn (Subscription $r) => $r->user?->name ? ($r->user->phone ?: $r->user->email) : $r->plan_code),
                TextColumn::make('plan_code')->label('Plan')->badge()->visibleFrom('md'),
                TextColumn::make('expires_at')
                    ->label('Ends')
                    ->since()
                    ->description(fn (Subscription $r) => $r->expires_at->format('d M, H:i'))
                    ->color(fn (Subscription $r) => $r->expires_at->lt(now()->addDays(2)) ? 'danger' : 'warning'),
                TextColumn::make('devices_count')->label('Devices')->alignCenter()->visibleFrom('md'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-right')
                    ->color('gray')
                    ->url(fn (Subscription $r) => SubscriptionResource::getUrl('edit', ['record' => $r])),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
