{{-- Bytes per kind of traffic: $categories = [category => [up, down]] --}}
@use('App\Services\TrafficRecorder')
@php
    $sum = array_sum(array_map(fn ($v) => $v[0] + $v[1], $categories)) ?: 1;
@endphp
@if (empty($categories))
    <p class="mt-muted">No traffic reported in this period.</p>
@else
    <div class="mt-scroll">
        <table class="mt-table">
            <thead>
                <tr>
                    <th>Kind of traffic</th>
                    <th style="width: 40%">Share</th>
                    <th class="num">Download</th>
                    <th class="num">Upload</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $cat => [$up, $down])
                    @php $pct = round(($up + $down) * 100 / $sum, 1); @endphp
                    <tr>
                        <td>{{ TrafficRecorder::CATEGORIES[$cat] ?? $cat }}</td>
                        <td>
                            <div style="display: flex; gap: .5rem; align-items: center">
                                <div class="mt-bar" style="flex: 1"><span class="{{ $cat }}" style="width: {{ max($pct, 1) }}%"></span></div>
                                <span class="mt-muted" style="width: 3.5rem; text-align: right">{{ $pct }}%</span>
                            </div>
                        </td>
                        <td class="num">{{ TrafficRecorder::bytes($down) }}</td>
                        <td class="num">{{ TrafficRecorder::bytes($up) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
