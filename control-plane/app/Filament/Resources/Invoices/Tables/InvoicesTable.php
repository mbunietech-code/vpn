<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use App\Services\ManualPaymentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Fits the screen on phones and desktops without sideways scrolling:
 * related facts share a column (as a description) and the row actions
 * live in one menu.
 */
class InvoicesTable
{
    public static function amount(Invoice $r): string
    {
        $decimals = $r->currency === 'tzs' ? 0 : 2;

        return strtoupper($r->currency) . ' ' . number_format($r->amount_cents / 100, $decimals);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.email')
                    ->label('Customer')
                    ->searchable()
                    // Phones: amount + plan under the customer (those columns are hidden there).
                    ->description(fn (Invoice $r) => self::amount($r) . ' · ' . $r->plan_code),
                TextColumn::make('amount_cents')->visibleFrom('md')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Invoice $r) => self::amount($r))
                    ->description(fn (Invoice $r) => $r->plan_code)
                    ->sortable(),
                TextColumn::make('provider')->visibleFrom('lg')
                    ->label('Method')
                    ->badge()
                    ->color(fn ($state) => $state === 'manual' ? 'warning' : 'gray')
                    ->description(fn (Invoice $r) => $r->payment_method),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', $state))
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'pending_review' => 'warning',
                        'pending' => 'gray',
                        'refunded' => 'info',
                        default => 'danger',
                    })
                    ->description(fn (Invoice $r) => $r->proof_path ? 'proof uploaded' : null),
                TextColumn::make('created_at')->visibleFrom('md')
                    ->label('Date')
                    ->since()
                    ->description(fn (Invoice $r) => $r->paid_at ? 'paid ' . $r->paid_at->format('d M H:i') : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending_review' => 'Pending review',
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                    'refunded' => 'Refunded',
                ]),
                SelectFilter::make('provider')->label('Method')->options([
                    'manual' => 'Manual', 'clickpesa' => 'ClickPesa', 'eduhub' => 'MbunieHub',
                    'admin' => 'Admin grant', 'stripe' => 'Stripe', 'cryptomus' => 'Cryptomus',
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('viewProof')
                        ->label('View proof')
                        ->icon('heroicon-o-photo')
                        ->visible(fn (Invoice $r) => (bool) $r->proof_path)
                        ->modalContent(fn (Invoice $r) => view('filament.invoice-proof', [
                            'url' => route('admin.invoice.proof', $r),
                            'note' => $r->meta['user_note'] ?? null,
                        ]))
                        ->modalSubmitAction(false),

                    Action::make('approve')
                        ->label('Approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Invoice $r) => in_array($r->status, ['pending', 'pending_review'], true))
                        ->schema([
                            Textarea::make('note')->label('Note (optional)')->rows(2),
                        ])
                        ->action(function (Invoice $r, array $data) {
                            app(ManualPaymentService::class)->approve(
                                $r, 'admin:' . auth()->id(), $data['note'] ?? null);
                            Notification::make()->success()
                                ->title('Approved — subscription activated')->send();
                        }),

                    Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Invoice $r) => in_array($r->status, ['pending', 'pending_review'], true))
                        ->schema([
                            Textarea::make('reason')->label('Reason')->required()->rows(2),
                        ])
                        ->action(function (Invoice $r, array $data) {
                            app(ManualPaymentService::class)->reject(
                                $r, 'admin:' . auth()->id(), $data['reason']);
                            Notification::make()->warning()->title('Rejected')->send();
                        }),

                    ViewAction::make(),
                ]),
            ]);
    }
}
