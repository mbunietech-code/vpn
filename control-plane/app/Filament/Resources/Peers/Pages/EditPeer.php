<?php

namespace App\Filament\Resources\Peers\Pages;

use App\Filament\Resources\Peers\PeerResource;
use Filament\Resources\Pages\EditRecord;

class EditPeer extends EditRecord
{
    protected static string $resource = PeerResource::class;

    // No Delete: VPN keys follow their subscription (suspend or delete it there).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
