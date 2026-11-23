<?php

namespace App\Filament\Resources\VipEvents\Pages;

use App\Filament\Resources\VipEvents\VipEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVipEvents extends ListRecords
{
    protected static string $resource = VipEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New VIP event'),
        ];
    }
}
