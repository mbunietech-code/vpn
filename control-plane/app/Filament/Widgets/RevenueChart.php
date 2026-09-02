<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Mapato — siku 14 zilizopita (USD ~)';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d)->startOfDay());

        $paid = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays(14)->startOfDay())
            ->get();

        $series = $days->map(function ($day) use ($paid) {
            return $paid
                ->filter(fn (Invoice $i) => $i->paid_at?->isSameDay($day))
                ->sum(fn (Invoice $i) => ($i->currency === 'cny' ? $i->amount_cents / 7.2 : $i->amount_cents) / 100);
        });

        return [
            'datasets' => [[
                'label' => 'USD',
                'data' => $series->map(fn ($v) => round($v, 2))->values()->all(),
                'backgroundColor' => '#2563EB',
                'borderRadius' => 4,
            ]],
            'labels' => $days->map(fn ($d) => $d->format('d/m'))->all(),
        ];
    }
}
