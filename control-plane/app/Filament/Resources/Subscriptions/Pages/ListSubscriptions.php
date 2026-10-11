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

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

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
