<?php

namespace App\Filament\Resources\Peers\Pages;

use App\Filament\Resources\Peers\PeerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPeer extends EditRecord
{
    protected static string $resource = PeerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
