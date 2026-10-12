<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\SubscriptionAdmin;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        /** @var User $u */
        $u = $this->getRecord();

        return $u->email ?? $u->phone ?? 'Customer #'.$u->id;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-m-no-symbol')
                ->color('warning')
                ->visible(fn (User $record) => $record->status === 'active' && ! $record->is_admin)
                ->requiresConfirmation()
                ->modalDescription("Cuts all of this customer's VPN access within about 15 seconds and signs them out of the app.")
                ->schema([TextInput::make('reason')->label('Reason (optional)')->maxLength(200)])
                ->action(function (User $record, array $data) {
                    app(SubscriptionAdmin::class)->setUserStatus($record, 'suspended', 'admin:'.auth()->id(), $data['reason'] ?? null);
                    Notification::make()->success()->title('Customer suspended')->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('restore')
                ->label('Restore')
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->visible(fn (User $record) => $record->status !== 'active')
                ->requiresConfirmation()
                ->action(function (User $record) {
                    app(SubscriptionAdmin::class)->setUserStatus($record, 'active', 'admin:'.auth()->id());
                    Notification::make()->success()->title('Customer restored')->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->visible(fn (User $record) => ! $record->is_admin)
                ->requiresConfirmation()
                ->modalHeading('Delete this customer?')
                ->modalDescription('Deletes the account with all its subscriptions, devices and payments (screenshots too). '
                    .'Any active VPN stops within about 15 seconds. This cannot be undone.')
                ->modalSubmitActionLabel('Delete customer')
                ->action(function (User $record) {
                    app(SubscriptionAdmin::class)->deleteCustomer($record, 'admin:'.auth()->id());
                    Notification::make()->success()->title('Customer deleted')->send();
                    $this->redirect(UserResource::getUrl('index'));
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Customer saved';
    }
}
