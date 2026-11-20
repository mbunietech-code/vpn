@use('App\Services\TrafficRecorder')
<x-filament-panels::page>
    @include('filament.traffic.styles')

    <div class="mt-wrap">
        <div class="mt-row">
            <span class="mt-muted">Breakdown below covers:</span>
            <select wire:model.live="hours" class="mt-select" aria-label="Period">
                <option value="1">Last hour</option>
                <option value="6">Last 6 hours</option>
                <option value="24">Last 24 hours</option>
                <option value="72">Last 3 days</option>
            </select>
        </div>

        @if ($hasShared && $otherDevices > 0)
            <div class="mt-note">
                This customer has {{ $otherDevices + 1 }} devices on one VPN key, and the server cannot always tell them apart.
                Rows marked <em>shared</em> may include the other device's traffic. Each source IP is usually one device or one network.
            </div>
        @endif

        <x-filament::section heading="What kind of traffic"
            description="Grouped from the destination name on the server; no web addresses are stored. Calls = WhatsApp/Zoom/Meet voice and video. VLESS (REALITY) and OpenVPN traffic is counted but not split.">
            @include('filament.traffic.categories', ['categories' => $categories])
        </x-filament::section>

        <x-filament::section heading="Connections by source IP" description="Where the device connected from, which protocol it used, and how much it moved.">
            @if ($sessions->isEmpty())
                <p class="mt-muted">No traffic reported in this period. Traffic appears within a minute once the node agent is version 0.5.0 or newer.</p>
            @else
                <div class="mt-scroll">
                    <table class="mt-table">
                        <thead>
                            <tr>
                                <th>Source IP</th>
                                <th>Protocol</th>
                                <th class="num">Download</th>
                                <th class="num">Upload</th>
                                <th class="num">Peak ↓</th>
                                <th>Last traffic</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sessions as $s)
                                <tr>
                                    <td>
                                        @if ($s['ip'])
                                            <a class="mt-link" href="https://ipinfo.io/{{ $s['ip'] }}" target="_blank" rel="noopener noreferrer"
                                                title="Look up the network (ISP) of this IP">{{ $s['ip'] }}</a>
                                            <span class="mt-muted">{{ $s['ip_version'] }}</span>
                                        @else
                                            <span class="mt-muted">unknown</span>
                                        @endif
                                        @if ($s['shared'] && $otherDevices > 0)
                                            <x-filament::badge color="gray" size="sm" style="display: inline-flex">shared</x-filament::badge>
                                        @endif
                                    </td>
                                    <td>{{ $s['protocol'] }}</td>
                                    <td class="num">{{ TrafficRecorder::bytes($s['down']) }}</td>
                                    <td class="num">{{ TrafficRecorder::bytes($s['up']) }}</td>
                                    <td class="num">{{ number_format($s['peak'], 1) }} Mbps</td>
                                    <td class="mt-muted">{{ $s['last']?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <p class="mt-muted">Kept for {{ TrafficRecorder::RETENTION_HOURS / 24 }} days, then deleted.</p>
    </div>
</x-filament-panels::page>
