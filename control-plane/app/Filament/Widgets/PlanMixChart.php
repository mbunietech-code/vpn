<?php

namespace App\Filament\Widgets;

use App\Models\Plan;
use App\Models\Subscription;
use Filament\Widgets\ChartWidget;

class PlanMixChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Active customers by plan';

    protected ?string $description = 'Which plans people are on right now.';

    protected ?string $maxHeight = '240px';

    private const COLORS = ['#2563EB', '#0D9488', '#7C3AED', '#D97706', '#DB2777', '#64748B'];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Subscription::where('status', 'active')
            ->where('expires_at', '>', now())
            ->selectRaw('plan_code, count(*) as total')
            ->groupBy('plan_code')
            ->orderByDesc('total')
            ->pluck('total', 'plan_code');

        $names = Plan::whereIn('code', $counts->keys())->pluck('name', 'code');

        return [
            'datasets' => [[
                'data' => $counts->values()->all(),
                'backgroundColor' => array_slice(array_pad(self::COLORS, max($counts->count(), 1), '#94A3B8'), 0, max($counts->count(), 1)),
                'borderWidth' => 0,
            ]],
            'labels' => $counts->keys()->map(fn ($code) => $names[$code] ?? $code)->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '62%',
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]]],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
