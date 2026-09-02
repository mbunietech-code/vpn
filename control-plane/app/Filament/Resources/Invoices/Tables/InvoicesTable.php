<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use App\Services\ManualPaymentService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.email')
                    ->description(fn (Invoice $r) => $r->user?->phone)
                    ->searchable(),
                TextColumn::make('plan_code')->badge(),
                TextColumn::make('provider')
                    ->badge()
                    ->color(fn ($state) => $state === 'manual' ? 'warning' : 'gray'),
                TextColumn::make('payment_method')->toggleable(),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Invoice $r) => number_format($state / 100, 2) . ' ' . strtoupper($r->currency)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'pending_review' => 'warning',
                        'pending' => 'gray',
                        'refunded' => 'info',
                        default => 'danger',
                    }),
                IconColumn::make('proof_path')
                    ->label('Proof')
                    ->boolean()
                    ->state(fn (Invoice $r) => (bool) $r->proof_path),
                TextColumn::make('created_at')->since()->sortable(),
                TextColumn::make('paid_at')->dateTime()->toggleable()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending_review' => 'Pending review',
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                    'refunded' => 'Refunded',
                ]),
                SelectFilter::make('provider')->options([
                    'manual' => 'Manual', 'stripe' => 'Stripe', 'cryptomus' => 'Cryptomus',
                ]),
            ])
            ->recordActions([
                Action::make('viewProof')
                    ->label('Proof')
                    ->icon('heroicon-o-photo')
                    ->visible(fn (Invoice $r) => (bool) $r->proof_path)
                    ->modalContent(fn (Invoice $r) => view('filament.invoice-proof', [
                        'url' => route('admin.invoice.proof', $r),
                        'note' => $r->meta['user_note'] ?? null,
                    ]))
                    ->modalSubmitAction(false),

                Action::make('approve')
                    ->label('Idhinisha')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Invoice $r) => in_array($r->status, ['pending', 'pending_review'], true))
                    ->form([
                        Textarea::make('note')->label('Note (hiari)')->rows(2),
                    ])
                    ->action(function (Invoice $r, array $data) {
                        app(ManualPaymentService::class)->approve(
                            $r, 'admin:' . auth()->id(), $data['note'] ?? null);
                        Notification::make()->success()
                            ->title('Imeidhinishwa — subscription imeanzishwa')->send();
                    }),

                Action::make('reject')
                    ->label('Kataa')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Invoice $r) => in_array($r->status, ['pending', 'pending_review'], true))
                    ->form([
                        Textarea::make('reason')->label('Sababu')->required()->rows(2),
                    ])
                    ->action(function (Invoice $r, array $data) {
                        app(ManualPaymentService::class)->reject(
                            $r, 'admin:' . auth()->id(), $data['reason']);
                        Notification::make()->warning()->title('Imekataliwa')->send();
                    }),

                ViewAction::make(),
            ]);
    }
}
