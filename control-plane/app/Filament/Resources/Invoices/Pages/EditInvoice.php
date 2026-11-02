<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\ManualPaymentService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Same as the list's Delete: audited, and the screenshot goes too.
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete this payment record?')
                ->modalDescription('The record and its screenshot are deleted. The subscription is not touched. This cannot be undone.')
                ->modalSubmitActionLabel('Delete')
                ->action(function (Invoice $record) {
                    app(ManualPaymentService::class)->delete($record, 'admin:'.auth()->id());
                    $this->redirect(InvoiceResource::getUrl('index'));
                }),
        ];
    }
}
