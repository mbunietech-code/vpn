<?php

namespace App\Filament\Resources\Peers;

use App\Filament\Resources\Peers\Pages\CreatePeer;
use App\Filament\Resources\Peers\Pages\EditPeer;
use App\Filament\Resources\Peers\Pages\ListPeers;
use App\Filament\Resources\Peers\Schemas\PeerForm;
use App\Filament\Resources\Peers\Tables\PeersTable;
use App\Models\Peer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PeerResource extends Resource
{
    protected static ?string $model = Peer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Miundombinu';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return PeerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PeersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeers::route('/'),
            'create' => CreatePeer::route('/create'),
            'edit' => EditPeer::route('/{record}/edit'),
        ];
    }
}
