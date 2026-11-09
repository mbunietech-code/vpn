<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected ?string $subheading = 'Payments from customers. "To review" are screenshots waiting for you to approve.';

    /** Payments waiting for an admin come first. */
    public function getTabs(): array
    {
        $review = Invoice::where('status', 'pending_review')->count();

        return [
            'review' => Tab::make('To review')
                ->icon(Heroicon::OutlinedEye)
                ->badge($review ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_review')),
            'paid' => Tab::make('Paid')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid')),
            'pending' => Tab::make('Not paid yet')
                ->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'failed' => Tab::make('Failed / refunded')
                ->icon(Heroicon::OutlinedXCircle)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['failed', 'refunded'])),
            'all' => Tab::make('All')->icon(Heroicon::OutlinedListBullet),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
