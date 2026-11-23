<?php

namespace App\Filament\Resources\VipEvents\RelationManagers;

use App\Filament\Resources\VipEvents\VipActions;
use App\Models\VipEvent;
use App\Models\VipPass;
use App\Services\ManualPaymentService;
use App\Services\VipPasses;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** Every pass sold (or given) for the event: code, devices used, payment, switches. */
class PassesRelationManager extends RelationManager
{
    protected static string $relationship = 'passes';

    protected static ?string $title = 'Passes';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Filament injects closure arguments by name: it must be $query.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('invoice', 'event'))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->formatStateUsing(fn (VipPass $r) => $r->displayCode())
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->copyable()
                    ->copyableState(fn (VipPass $r) => $r->displayCode())
                    ->searchable()
                    ->description(fn (VipPass $r) => trim(($r->contact ?? '').' '.($r->note ? '· '.$r->note : ''))),
                TextColumn::make('state')
                    ->label('Status')
                    ->state(fn (VipPass $r) => $r->state())
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'review' => 'warning',
                        'pending' => 'gray',
                        'ended' => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('devices')
                    ->label('Devices')
                    ->state(fn (VipPass $r) => $r->devicesUsed().' / '.$r->devices)
                    ->tooltip('Used / paid for'),
                TextColumn::make('payment')
                    ->label('Payment')
                    ->visibleFrom('md')
                    ->state(fn (VipPass $r) => $r->source === 'manual'
                        ? 'Given by admin'
                        : ($r->invoice
                            ? VipEvent::money((int) $r->invoice->amount_cents, (string) $r->invoice->currency).' · '.($r->invoice->payment_method ?: $r->invoice->provider)
                            : '—'))
                    ->description(fn (VipPass $r) => $r->invoice && $r->source !== 'manual' ? $r->invoice->status : null),
                TextColumn::make('created_at')->label('Bought')->since()->visibleFrom('md')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Paid / given',
                    'pending' => 'Not paid yet',
                ])->default('active'),
            ])
            ->headerActions([
                VipActions::give(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('link')
                        ->label('Pass link')
                        ->icon('heroicon-m-link')
                        ->modalHeading(fn (VipPass $r) => 'Pass '.$r->displayCode())
                        ->modalDescription('Send this link to the guest: it shows their code and the app download. It is private to them.')
                        ->modalContent(function (VipPass $r) {
                            $url = $r->pageUrl();

                            return new HtmlString('<img alt="QR" src="'.(new QRCode)->render($url).'" style="width:220px;height:220px;margin:0 auto 12px;display:block;background:#fff;padding:8px;border-radius:8px">'
                                .'<input readonly onclick="this.select()" style="width:100%;font-family:monospace;font-size:13px;padding:8px;border:1px solid #ccc;border-radius:6px" value="'.e($url).'">');
                        })
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Action::make('approve')
                        ->label('Approve payment')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (VipPass $r) => $r->status === 'pending' && $r->invoice?->status === 'pending_review')
                        ->requiresConfirmation()
                        ->modalDescription(fn (VipPass $r) => 'Check the screenshot first (Sales › Invoices). The pass works at once.'
                            .($r->invoice?->proof_path ? '' : ' No screenshot was uploaded.'))
                        ->action(function (VipPass $r) {
                            app(ManualPaymentService::class)->approve($r->invoice, 'admin:'.auth()->id());
                            Notification::make()->success()->title('Approved — pass '.$r->displayCode().' is active')->send();
                        }),
                    Action::make('block')
                        ->label('Switch this pass off')
                        ->icon('heroicon-m-pause-circle')
                        ->color('danger')
                        ->visible(fn (VipPass $r) => $r->status === 'active' && ! $r->blocked)
                        ->requiresConfirmation()
                        ->modalDescription('Its devices lose the VPN within about 15 seconds.')
                        ->action(function (VipPass $r) {
                            app(VipPasses::class)->setBlocked($r, true, 'admin:'.auth()->id());
                            Notification::make()->success()->title('Pass switched off')->send();
                        }),
                    Action::make('unblock')
                        ->label('Switch this pass on')
                        ->icon('heroicon-m-play-circle')
                        ->color('success')
                        ->visible(fn (VipPass $r) => $r->status === 'active' && $r->blocked)
                        ->action(function (VipPass $r) {
                            app(VipPasses::class)->setBlocked($r, false, 'admin:'.auth()->id());
                            Notification::make()->success()->title('Pass switched on')->send();
                        }),
                    Action::make('reset')
                        ->label('Free its devices')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->visible(fn (VipPass $r) => $r->status === 'active' && $r->devicesUsed() > 0)
                        ->requiresConfirmation()
                        ->modalDescription('For a guest who changed phone: the devices using this code are signed out, and the code can be used again on new ones.')
                        ->action(function (VipPass $r) {
                            $n = app(VipPasses::class)->resetDevices($r, 'admin:'.auth()->id());
                            Notification::make()->success()->title("{$n} device(s) freed")->send();
                        }),
                ]),
            ]);
    }
}
