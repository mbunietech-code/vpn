<?php

namespace App\Filament\Resources\VipEvents\Pages;

use App\Filament\Resources\VipEvents\VipActions;
use App\Filament\Resources\VipEvents\VipEventResource;
use App\Models\VipEvent;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVipEvent extends EditRecord
{
    protected static string $resource = VipEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VipActions::qr(),
            VipActions::give(),
            VipActions::toggleAccess()->after(fn () => $this->refreshFormData(['access_enabled'])),
            // Sold passes are records of payments: an event with passes is switched off, not deleted.
            DeleteAction::make()->visible(fn (VipEvent $record) => ! $record->passes()->exists()),
        ];
    }
}
