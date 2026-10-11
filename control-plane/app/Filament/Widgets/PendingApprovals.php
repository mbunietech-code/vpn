<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Services\ManualPaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingApprovals extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected static ?string $heading = 'Payments awaiting approval';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '20s';

    public static function canView(): bool
    {
        return Invoice::whereIn('status', ['pending', 'pending_review'])->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Invoice::query()
                    ->whereIn('status', ['pending', 'pending_review'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('user.email')
                    ->label('Customer')
                    ->description(fn (Invoice $r) => $r->user?->phone),
                TextColumn::make('plan_code')->label('Plan')->badge(),
                TextColumn::make('payment_method')->label('Method')->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Invoice $r) => number_format($state / 100, 2) . ' ' . strtoupper($r->currency)),
                TextColumn::make('proof_path')
                    ->label('Proof')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Ipo' : 'Haipo')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Time')->since(),
            ])
            ->recordActions([
                Action::make('proof')
                    ->label('View proof')
                    ->icon('heroicon-m-photo')
                    ->color('gray')
                    ->visible(fn (Invoice $r) => (bool) $r->proof_path)
                    ->modalContent(fn (Invoice $r) => view('filament.invoice-proof', [
                        'url' => route('admin.invoice.proof', $r),
                        'note' => $r->meta['user_note'] ?? null,
                    ]))
                    ->modalSubmitAction(false),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Invoice $r) {
                        app(ManualPaymentService::class)->approve($r, 'admin:' . auth()->id());
                        Notification::make()->success()->title('Subscription activated')->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->form([Textarea::make('reason')->label('Reason')->required()])
                    ->action(function (Invoice $r, array $data) {
                        app(ManualPaymentService::class)->reject($r, 'admin:' . auth()->id(), $data['reason']);
                        Notification::make()->warning()->title('Rejected')->send();
                    }),
            ])
            ->paginated(false);
    }
}
