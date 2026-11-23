<?php

namespace App\Filament\Resources\VipEvents;

use App\Filament\Resources\VipEvents\Pages\CreateVipEvent;
use App\Filament\Resources\VipEvents\Pages\EditVipEvent;
use App\Filament\Resources\VipEvents\Pages\ListVipEvents;
use App\Filament\Resources\VipEvents\RelationManagers\PassesRelationManager;
use App\Filament\Resources\VipEvents\Schemas\VipEventForm;
use App\Filament\Resources\VipEvents\Tables\VipEventsTable;
use App\Models\VipEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin › Sales › VIP events: one QR per event, guests pay per device and
 * get in with a code. The end time and the on/off switch rule every pass.
 */
class VipEventResource extends Resource
{
    protected static ?string $model = VipEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'VIP events';

    protected static ?string $modelLabel = 'VIP event';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return VipEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VipEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PassesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVipEvents::route('/'),
            'create' => CreateVipEvent::route('/create'),
            'edit' => EditVipEvent::route('/{record}/edit'),
        ];
    }
}
