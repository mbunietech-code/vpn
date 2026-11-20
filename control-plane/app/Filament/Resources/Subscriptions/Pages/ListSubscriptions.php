<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionAdmin;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    public function getTabs(): array
    {
        $expiring = fn (Builder $query) => $query->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)]);

        return [
            'active' => Tab::make('Active')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->badge(\App\Models\Subscription::where('status', 'active')->where('expires_at', '>', now())->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')->where('expires_at', '>', now())),
            'expiring' => Tab::make('Ending in 7 days')
                ->icon(Heroicon::OutlinedClock)
                ->badge($expiring(\App\Models\Subscription::query())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing($expiring),
            'ended' => Tab::make('Expired / suspended')
                ->icon(Heroicon::OutlinedPauseCircle)
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn ($w) => $w->whereIn('status', ['expired', 'suspended'])
                    ->orWhere(fn ($x) => $x->where('status', 'active')->where('expires_at', '<=', now())))),
            'all' => Tab::make('All')->icon(Heroicon::OutlinedListBullet),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Replaces the raw "create" form: provisions peers and is audited.
            Action::make('grant')
                ->label('Grant plan')
                ->icon('heroicon-m-gift')
                ->modalDescription('Give a customer a plan without a payment (gift, compensation or testing). It is recorded as a free admin invoice.')
                ->schema([
                    Select::make('user_id')
                        ->label('Customer')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                            ->limit(20)->get()
                            ->mapWithKeys(fn (User $u) => [$u->id => $u->email ?? $u->phone]))
                        ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? ($u->email ?? $u->phone) : null),
                    Select::make('plan_code')
                        ->label('Plan')
                        ->required()
                        ->options(fn () => Plan::orderBy('sort')->get()->mapWithKeys(fn (Plan $p) => [$p->code => "{$p->name} ({$p->days} days)"])),
                    TextInput::make('note')->label('Note (optional)')->maxLength(200),
                ])
                ->action(function (array $data) {
                    $sub = app(SubscriptionAdmin::class)->grant(
                        User::findOrFail($data['user_id']),
                        Plan::where('code', $data['plan_code'])->firstOrFail(),
                        'admin:' . auth()->id(),
                        $data['note'] ?? null,
                    );
                    Notification::make()->success()->title('Plan granted')
                        ->body('Active until ' . $sub->expires_at->format('d M Y H:i'))->send();
                }),
        ];
    }
}
