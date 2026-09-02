<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OpenAlerts extends TableWidget
{
    protected static ?string $heading = 'Tahadhari (hazijashughulikiwa)';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return Alert::whereNull('acknowledged_at')->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Alert::query()->whereNull('acknowledged_at')->latest())
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn ($s) => match ($s) {
                        'critical' => 'danger',
                        'warn' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('title')->wrap(),
                TextColumn::make('ai_summary')
                    ->label('AI')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')->since(),
            ])
            ->recordActions([
                Action::make('ack')
                    ->label('Nimeshughulikia')
                    ->icon('heroicon-m-check')
                    ->action(function (Alert $r) {
                        $r->update([
                            'acknowledged_at' => now(),
                            'acknowledged_by' => 'admin:' . auth()->id(),
                        ]);
                        Notification::make()->success()->title('Imefungwa')->send();
                    }),
            ])
            ->paginated(false);
    }
}
