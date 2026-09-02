<?php

namespace App\Filament\Resources\Peers\Pages;

use App\Filament\Resources\Peers\PeerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPeers extends ListRecords
{
    protected static string $resource = PeerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
