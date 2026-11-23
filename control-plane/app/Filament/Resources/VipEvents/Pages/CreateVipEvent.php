<?php

namespace App\Filament\Resources\VipEvents\Pages;

use App\Filament\Resources\VipEvents\VipEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVipEvent extends CreateRecord
{
    protected static string $resource = VipEventResource::class;

    /** Straight to the event, where the QR code is. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
