<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Services\RevenueSummary;
use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Revenue (≈ USD)';

    protected ?string $description = 'Paid invoices per day, all currencies converted roughly to dollars.';

    protected ?string $maxHeight = '240px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['14' => 'Last 14 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $count = (int) ($this->filter ?: 30);
        $days = collect(range($count - 1, 0))->map(fn ($d) => now()->subDays($d)->startOfDay());
        $paid = RevenueSummary::paid($days->first());

        $series = $days->map(fn ($day) => round(RevenueSummary::usd(
            $paid->filter(fn (Invoice $i) => $i->paid_at->isSameDay($day))
        ), 2));

        return [
            'datasets' => [[
                'label' => 'USD',
                'data' => $series->values()->all(),
                'borderColor' => '#2563EB',
                'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                'fill' => true,
                'tension' => 0.35,
                'pointRadius' => 0,
                'pointHoverRadius' => 4,
                'borderWidth' => 2,
            ]],
            'labels' => $days->map(fn ($d) => $d->format('d/m'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 8]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
