<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionAdmin;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSubscription extends EditRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Same as the list's Delete: the nodes drop the keys and it is audited.
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete this subscription?')
                ->modalDescription(fn (Subscription $record) => ($record->isActive() ? 'It is still active: the customer loses VPN access within about 15 seconds. ' : '')
                    .'Its devices and VPN keys are deleted too. Payments are kept. This cannot be undone.')
                ->modalSubmitActionLabel('Delete')
                ->action(function (Subscription $record) {
                    app(SubscriptionAdmin::class)->delete($record, 'admin:'.auth()->id());
                    $this->redirect(SubscriptionResource::getUrl('index'));
                }),
        ];
    }
}
