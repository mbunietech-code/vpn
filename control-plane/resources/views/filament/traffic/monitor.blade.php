@use('App\Filament\Resources\Devices\DeviceResource')
@use('App\Filament\Resources\Subscriptions\SubscriptionResource')
@use('App\Services\TrafficRecorder')
<x-filament-panels::page>
    @include('filament.traffic.styles')
    @php
        $maxBytes = max(1, $consumers->max(fn ($c) => $c['up'] + $c['down']) ?? 1);
    @endphp

    <div class="mt-wrap">
        <div class="mt-row">
            <div>
                <strong>Now:</strong> {{ number_format($nowDown, 1) }} Mbps ↓ · {{ number_format($nowUp, 1) }} Mbps ↑
                <span class="mt-muted">(all customers, last minute)</span>
                <br>
                <span class="mt-muted">{{ $windowLabel }}: {{ TrafficRecorder::bytes($totalDown) }} ↓ · {{ TrafficRecorder::bytes($totalUp) }} ↑</span>
            </div>
            <select wire:model.live="minutes" class="mt-select" aria-label="Period">
                @foreach (\App\Filament\Pages\TrafficMonitor::WINDOWS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <x-filament::section heading="Heaviest devices" :description="$windowLabel.'. Click a device to see its speed graph and what kind of traffic it is.'">
            @if ($consumers->isEmpty())
                <p class="mt-muted">No traffic reported in this period. Traffic appears within a minute once the node agent is version 0.5.0 or newer.</p>
            @else
                <div class="mt-scroll">
                    <table class="mt-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer / device</th>
                                <th style="width: 22%">Data</th>
                                <th class="num">Download</th>
                                <th class="num">Upload</th>
                                <th class="num">Now ↓</th>
                                <th class="num">Peak ↓</th>
                                <th>Mostly</th>
                                <th>Protocol</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($consumers as $i => $c)
                                @php
                                    $url = $c['device']
                                        ? DeviceResource::getUrl('traffic', ['record' => $c['device']])
                                        : ($c['subscription'] ? SubscriptionResource::getUrl('edit', ['record' => $c['subscription']]) : null);
                                    $label = $c['device']
                                        ? (trim(($c['device']->model ?: $c['device']->name ?: '').' '.($c['device']->platform ? '('.$c['device']->platform.')' : '')) ?: 'device #'.$c['device']->id)
                                        : 'devices not told apart';
                                @endphp
                                <tr>
                                    <td class="mt-muted">{{ $i + 1 }}</td>
                                    <td>
                                        @if ($url)
                                            <a class="mt-link" href="{{ $url }}">{{ $c['customer'] }}</a>
                                        @else
                                            {{ $c['customer'] }}
                                        @endif
                                        <div class="mt-muted">{{ $label }}</div>
                                    </td>
                                    <td>
                                        <div class="mt-bar"><span class="{{ $c['top_category'] }}" style="width: {{ max(1, round(($c['up'] + $c['down']) * 100 / $maxBytes)) }}%"></span></div>
                                    </td>
                                    <td class="num">{{ TrafficRecorder::bytes($c['down']) }}</td>
                                    <td class="num">{{ TrafficRecorder::bytes($c['up']) }}</td>
                                    <td class="num">{{ $c['now_down'] > 0 ? number_format($c['now_down'], 1).' Mbps' : '—' }}</td>
                                    <td class="num">{{ number_format($c['peak_down'], 1) }} Mbps</td>
                                    <td>
                                        {{ $c['top_category'] ? (TrafficRecorder::CATEGORIES[$c['top_category']] ?? $c['top_category']) : '—' }}
                                        @if ($c['calls'] > 0)
                                            <div class="mt-muted">calls: {{ TrafficRecorder::bytes($c['calls']) }}</div>
                                        @endif
                                    </td>
                                    <td class="mt-muted">{{ implode(', ', $c['protocols']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Traffic by kind, all customers" :description="$windowLabel.'. Grouped from destination names on the server; no web addresses are stored.'">
            @include('filament.traffic.categories', ['categories' => $categories])
        </x-filament::section>

        <p class="mt-muted">Per-customer traffic is kept for {{ TrafficRecorder::RETENTION_HOURS / 24 }} days, then deleted.</p>
    </div>
</x-filament-panels::page>
