<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getSubheading(): ?string
    {
        $hour = (int) now('Africa/Dar_es_Salaam')->format('G');
        $greeting = match (true) {
            $hour < 12 => 'Habari za asubuhi',
            $hour < 17 => 'Habari za mchana',
            default => 'Habari za jioni',
        };

        return $greeting.', '.strtok((string) auth()->user()?->name, ' ').'. Mauzo, malipo yanayosubiri na hali ya mtandao kwa haraka.';
    }

    protected function getHeaderActions(): array
    {
        $pending = Invoice::whereIn('status', ['pending', 'pending_review'])->count();

        return [
            Action::make('payments')
                ->label($pending > 0 ? "Approve payments ($pending)" : 'Payments')
                ->icon('heroicon-m-banknotes')
                ->color($pending > 0 ? 'danger' : 'gray')
                ->url(InvoiceResource::getUrl('index', $pending > 0 ? ['filters' => ['status' => ['value' => 'pending_review']]] : [])),
            Action::make('plans')
                ->label('Plans & prices')
                ->icon('heroicon-m-ticket')
                ->color('gray')
                ->url(PlanResource::getUrl('index')),
            Action::make('methods')
                ->label('Payment methods')
                ->icon('heroicon-m-qr-code')
                ->color('gray')
                ->url(PaymentMethodResource::getUrl('index')),
            Action::make('download')
                ->label('Download page')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(route('download'), shouldOpenInNewTab: true),
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
