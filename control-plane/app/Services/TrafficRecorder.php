<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Node;
use App\Models\Peer;
use App\Models\TrafficSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stores the node agent's per-session usage report (POST /api/node/usage)
 * into 5-minute buckets and answers the admin's traffic questions.
 *
 * Privacy: the agent sends no host names, only coarse categories. Rows are
 * pruned after RETENTION_HOURS.
 */
class TrafficRecorder
{
    public const BUCKET_SECONDS = 300;

    public const RETENTION_HOURS = 72;

    /** Category => label, in the order the admin sees them. */
    public const CATEGORIES = [
        'calls' => 'Calls (WhatsApp, Zoom, …)',
        'video' => 'Video (YouTube, TikTok, …)',
        'downloads' => 'Downloads & updates',
        'social' => 'Social (Facebook, Instagram, …)',
        'messaging' => 'Chat apps (WhatsApp, Telegram)',
        'web' => 'Web & other apps',
        'other_udp' => 'Other UDP (games, other calls)',
        'unknown' => 'Not split (VLESS / OpenVPN)',
    ];

    /** Protocol label as the admin knows it. */
    public static function protocolLabel(string $protocol, string $inbound = ''): string
    {
        return match (true) {
            $protocol === 'hysteria2' && str_contains($inbound, 'obfs') => 'Hysteria2 Obfs',
            $protocol === 'hysteria2' => 'Hysteria2',
            $protocol === 'vless-reality' => 'REALITY',
            $protocol === 'openvpn' => 'OpenVPN'.(str_contains($inbound, 'udp') ? ' UDP' : ' TCP'),
            default => $protocol,
        };
    }

    public static function ipVersion(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        return str_contains($ip, ':') ? 'IPv6' : 'IPv4';
    }

    /**
     * @param  array{window_seconds?: int, sessions?: list<array<string, mixed>>, unattributed?: array<int, int>}  $report
     * @return int sessions stored
     */
    public function record(Node $node, array $report, ?Carbon $now = null): int
    {
        $now ??= now();
        $bucket = Carbon::createFromTimestamp(intdiv($now->timestamp, self::BUCKET_SECONDS) * self::BUCKET_SECONDS);
        $window = max(1, (int) ($report['window_seconds'] ?? 60));

        $sessions = collect($report['sessions'] ?? []);
        if ($sessions->isEmpty()) {
            return 0;
        }

        $peers = Peer::where('node_id', $node->id)
            ->whereIn('remote_id', $sessions->pluck('remote_id')->unique()->all())
            ->get()
            ->groupBy('remote_id');

        $devices = Device::whereIn('subscription_id', $peers->flatten()->pluck('subscription_id')->unique()->all())
            ->whereNull('revoked_at')
            ->get()
            ->groupBy('subscription_id');

        $stored = 0;
        foreach ($sessions as $s) {
            $peer = $this->peerFor($peers->get($s['remote_id'] ?? '', collect()), (string) ($s['protocol'] ?? ''));
            if (! $peer) {
                continue; // a credential this node no longer has
            }

            $up = max(0, (int) ($s['up'] ?? 0));
            $down = max(0, (int) ($s['down'] ?? 0));
            $ip = (string) ($s['source_ip'] ?? '');
            $inbound = (string) ($s['inbound'] ?? '');

            DB::transaction(function () use ($node, $peer, $bucket, $s, $up, $down, $ip, $inbound, $window, $devices) {
                $row = TrafficSample::lockForUpdate()->firstOrNew([
                    'peer_id' => $peer->id,
                    'inbound' => $inbound,
                    'source_ip' => $ip,
                    'bucket_at' => $bucket,
                ]);

                $row->fill([
                    'node_id' => $node->id,
                    'subscription_id' => $peer->subscription_id,
                    'protocol' => $peer->protocol,
                    'device_id' => $row->device_id
                        ?? $this->guessDevice($devices->get($peer->subscription_id, collect()), $ip)?->id,
                    'bytes_up' => (int) $row->bytes_up + $up,
                    'bytes_down' => (int) $row->bytes_down + $down,
                    'peak_up_mbps' => max((float) $row->peak_up_mbps, $this->mbps($s['peak_up_mbps'] ?? 0)),
                    'peak_down_mbps' => max((float) $row->peak_down_mbps, $this->mbps($s['peak_down_mbps'] ?? 0)),
                    'last_up_mbps' => round($up * 8 / $window / 1e6, 2),
                    'last_down_mbps' => round($down * 8 / $window / 1e6, 2),
                    'connections' => max((int) $row->connections, (int) ($s['connections'] ?? 0)),
                    'categories' => $this->mergeCategories($row->categories ?? [], (array) ($s['categories'] ?? [])),
                ]);
                $row->save();

                Peer::whereKey($peer->id)->update([
                    'bytes_up' => DB::raw('bytes_up + '.$up),
                    'bytes_down' => DB::raw('bytes_down + '.$down),
                ]);
            });
            $stored++;
        }

        return $stored;
    }

    /** @param  Collection<int, Peer>  $candidates */
    private function peerFor(Collection $candidates, string $protocol): ?Peer
    {
        return $candidates->firstWhere('protocol', $protocol) ?? $candidates->first();
    }

    /**
     * Devices share their subscription's VPN credential, so the node cannot
     * tell them apart. Match the app's last heartbeat IP, else the only device.
     *
     * @param  Collection<int, Device>  $devices
     */
    private function guessDevice(Collection $devices, string $ip): ?Device
    {
        if ($ip !== '' && ($byIp = $devices->firstWhere('last_ip', $ip))) {
            return $byIp;
        }

        return $devices->count() === 1 ? $devices->first() : null;
    }

    private function mbps(mixed $v): float
    {
        return round(min(max((float) $v, 0), 999999), 2);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $have
     * @param  array<string, mixed>  $add
     * @return array<string, array{0: int, 1: int}>|null
     */
    private function mergeCategories(array $have, array $add): ?array
    {
        foreach ($add as $cat => $v) {
            if (! array_key_exists($cat, self::CATEGORIES) || ! is_array($v)) {
                continue;
            }
            $have[$cat] = [
                (int) ($have[$cat][0] ?? 0) + max(0, (int) ($v[0] ?? 0)),
                (int) ($have[$cat][1] ?? 0) + max(0, (int) ($v[1] ?? 0)),
            ];
        }

        return $have ?: null;
    }

    public function prune(): int
    {
        return TrafficSample::where('bucket_at', '<', now()->subHours(self::RETENTION_HOURS))->delete();
    }

    // ---- reading ------------------------------------------------------------

    /**
     * Samples that belong to this device: its own rows, plus its
     * subscription's rows nobody could pin to a device (shared credential).
     */
    public function deviceQuery(Device $device, Carbon $since)
    {
        return TrafficSample::where('subscription_id', $device->subscription_id)
            ->where('bucket_at', '>=', $since)
            ->where(fn ($q) => $q->where('device_id', $device->id)->orWhereNull('device_id'));
    }

    /**
     * Category totals over samples: category => [up, down]. Bytes the node
     * could not split (VLESS, OpenVPN) are counted as "unknown".
     *
     * @param  iterable<TrafficSample>  $samples
     * @return array<string, array{0: int, 1: int}>
     */
    public static function categoryTotals(iterable $samples): array
    {
        $out = [];
        foreach ($samples as $s) {
            $cats = $s->categories ?? [];
            $splitUp = $splitDown = 0;
            foreach ($cats as $cat => [$up, $down]) {
                $out[$cat][0] = ($out[$cat][0] ?? 0) + $up;
                $out[$cat][1] = ($out[$cat][1] ?? 0) + $down;
                $splitUp += $up;
                $splitDown += $down;
            }
            $restUp = max(0, $s->bytes_up - $splitUp);
            $restDown = max(0, $s->bytes_down - $splitDown);
            if ($restUp + $restDown > 0) {
                $out['unknown'][0] = ($out['unknown'][0] ?? 0) + $restUp;
                $out['unknown'][1] = ($out['unknown'][1] ?? 0) + $restDown;
            }
        }
        uasort($out, fn ($a, $b) => ($b[0] + $b[1]) <=> ($a[0] + $a[1]));

        return $out;
    }

    /**
     * Heaviest sessions since $since, grouped by device (or by subscription
     * when the device is unknown), heaviest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topConsumers(Carbon $since, int $limit = 25): Collection
    {
        $samples = TrafficSample::where('bucket_at', '>=', $since)
            ->with(['subscription.user', 'device'])
            ->get();

        return $samples
            ->groupBy(fn (TrafficSample $s) => $s->device_id ? "d{$s->device_id}" : "s{$s->subscription_id}")
            ->map(function (Collection $rows) {
                /** @var TrafficSample $first */
                $first = $rows->sortByDesc('updated_at')->first();
                $cats = self::categoryTotals($rows);
                $topCat = array_key_first(array_filter($cats, fn ($k) => $k !== 'unknown', ARRAY_FILTER_USE_KEY));
                $recent = $rows->filter(fn (TrafficSample $s) => $s->updated_at?->gt(now()->subMinutes(3)));

                return [
                    'device' => $first->device,
                    'subscription' => $first->subscription,
                    'customer' => $first->subscription?->user?->email ?? $first->subscription?->user?->phone ?? '—',
                    'up' => (int) $rows->sum('bytes_up'),
                    'down' => (int) $rows->sum('bytes_down'),
                    'peak_down' => (float) $rows->max('peak_down_mbps'),
                    'peak_up' => (float) $rows->max('peak_up_mbps'),
                    'now_down' => (float) $recent->sum('last_down_mbps'),
                    'now_up' => (float) $recent->sum('last_up_mbps'),
                    'protocols' => $rows->map(fn ($s) => self::protocolLabel($s->protocol, $s->inbound))->unique()->values()->all(),
                    'calls' => array_sum($cats['calls'] ?? [0, 0]),
                    'top_category' => $topCat,
                    'last_at' => $first->updated_at,
                ];
            })
            ->sortByDesc(fn ($r) => $r['up'] + $r['down'])
            ->take($limit)
            ->values();
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0)).' '.$units[$i];
    }
}
