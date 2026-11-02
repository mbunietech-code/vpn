<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Services\DeviceAdmin;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Same as the list's Delete (audited); a removed device stays as a block.
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-m-trash')
                ->color('gray')
                ->visible(fn (Device $record) => $record->revoked_at === null)
                ->requiresConfirmation()
                ->modalHeading('Delete this device?')
                ->modalDescription('Takes it off the list and frees a place in the customer\'s device limit. '
                    .'It is not blocked: if the app is still installed, it appears again the next time it connects. '
                    .'To block a device, use Remove device on the Devices list.')
                ->modalSubmitActionLabel('Delete')
                ->action(function (Device $record) {
                    app(DeviceAdmin::class)->delete($record, auth()->user()?->email ?? 'admin');
                    $this->redirect(DeviceResource::getUrl('index'));
                }),
        ];
    }
}
