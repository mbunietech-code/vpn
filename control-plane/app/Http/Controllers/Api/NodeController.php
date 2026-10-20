<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Node;
use App\Models\NodeTraffic;
use App\Models\Peer;
use App\Services\NodeCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Node-agent facing API. The agent authenticates with its per-node bearer
 * token (matched against nodes.api_secret) and PULLS the authoritative peer
 * list, then POSTs health. Node makes only outbound calls (firewall-friendly).
 */
class NodeController extends Controller
{
    private function node(Request $request): ?Node
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        return Node::all()->first(fn (Node $n) => hash_equals((string) $n->api_secret, $token));
    }

    public function peers(Request $request): JsonResponse
    {
        $node = $this->node($request);
        if (! $node) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $peers = Peer::where('node_id', $node->id)
            ->where('status', 'active')
            ->whereHas('subscription', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', now()))
            ->get()
            ->map(fn (Peer $p) => [
                'remote_id' => $p->remote_id,
                'protocol' => $p->protocol,
                'secret' => $p->protocol === 'hysteria2' ? $p->secret : null,
                // Serial of the certificate whose profile we hold; the agent
                // uploads again when its current certificate differs.
                'openvpn_serial' => $p->protocol === 'openvpn' ? $p->openvpn_cert_serial : null,
                'status' => 'active',
            ])->values();

        // The agent re-applies only when `version` changes. Fold the actual
        // active set into it so an expiry takes effect on the next sync even
        // if nothing bumped peer_version (e.g. the sweep cron isn't running).
        $fingerprint = $peers->map(fn ($p) => $p['protocol'] . ':' . $p['remote_id'] . ':' . $p['openvpn_serial'])->sort()->implode(',');

        return response()->json([
            'version' => (int) sprintf('%u', crc32($node->peer_version . '|' . $fingerprint)),
            'peers' => $peers,
        ]);
    }

    /**
     * The agent issued a subscription's OpenVPN certificate (the CA stays on
     * the node) and hands us the client profile to serve to that subscriber.
     */
    public function openvpnProfile(Request $request): JsonResponse
    {
        $node = $this->node($request);
        if (! $node) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'remote_id' => ['required', 'string', 'max:64'],
            'serial' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Fa-f]+$/'],
            'profile' => ['required', 'string', 'max:20000'],
        ]);

        foreach (['<ca>', '<cert>', '<key>', '<tls-crypt>'] as $block) {
            if (! str_contains($data['profile'], $block)) {
                return response()->json(['error' => "profile is missing {$block}"], 422);
            }
        }

        $peer = Peer::where('node_id', $node->id)
            ->where('protocol', 'openvpn')
            ->where('remote_id', $data['remote_id'])
            ->first();
        if (! $peer) {
            return response()->json(['error' => 'unknown peer'], 404);
        }

        $peer->update([
            'openvpn_profile' => $data['profile'],
            'openvpn_cert_serial' => strtoupper($data['serial']),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /** Adds the agent's network deltas to today's row; peaks keep the maximum. */
    private function recordTraffic(Node $node, array $net): void
    {
        $day = NodeTraffic::firstOrCreate(['node_id' => $node->id, 'day' => now()->toDateString()]);
        NodeTraffic::whereKey($day->id)->update([
            'rx_bytes' => DB::raw('rx_bytes + '.(int) ($net['rx_bytes'] ?? 0)),
            'tx_bytes' => DB::raw('tx_bytes + '.(int) ($net['tx_bytes'] ?? 0)),
            'peak_rx_mbps' => max((float) $day->peak_rx_mbps, (float) ($net['peak_rx_mbps'] ?? 0)),
            'peak_tx_mbps' => max((float) $day->peak_tx_mbps, (float) ($net['peak_tx_mbps'] ?? 0)),
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $node = $this->node($request);
        if (! $node) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:32'],
            'applied_version' => ['nullable', 'integer'],
            'uptime_seconds' => ['nullable', 'integer'],
            'active_peers' => ['nullable', 'integer'],
            'engines' => ['nullable', 'array'],
            'traffic' => ['nullable', 'array'],
            'last_error' => ['nullable', 'string', 'max:2000'],
            'node_info' => ['nullable', 'array'],
            'node_info.public_host' => ['nullable', 'string', 'max:255'],
            'node_info.reality_pubkey' => ['nullable', 'string', 'max:128'],
            'node_info.reality_short_id' => ['nullable', 'string', 'max:32'],
            'node_info.reality_sni' => ['nullable', 'string', 'max:255'],
            'node_info.reality_port' => ['nullable', 'integer', 'between:1,65535'],
            'node_info.hysteria_port' => ['nullable', 'integer', 'between:1,65535'],
            'node_info.hysteria_port_range' => ['nullable', 'regex:/^\d+-\d+$/'],
            'node_info.hysteria_cert_sha256' => ['nullable', 'string', 'max:128'],
            'node_info.hysteria_cert_pem' => ['nullable', 'string', 'max:8000'],
            // Network use since the agent's last successful report.
            'net' => ['nullable', 'array'],
            'net.rx_bytes' => ['nullable', 'integer', 'min:0'],
            'net.tx_bytes' => ['nullable', 'integer', 'min:0'],
            'net.peak_rx_mbps' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'net.peak_tx_mbps' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $engines = $data['engines'] ?? [];
        $enginesDown = collect($engines)->filter(fn ($s) => $s !== 'up')->keys();

        $attrs = [
            'health' => [
                'uptime_seconds' => $data['uptime_seconds'] ?? null,
                'active_peers' => $data['active_peers'] ?? null,
                'engines' => $engines,
                'applied_version' => $data['applied_version'] ?? null,
                'last_error' => $data['last_error'] ?? null,
            ],
            'agent_version' => $data['agent_version'] ?? $node->agent_version,
            'last_health_at' => now(),
        ];

        // The node is the source of truth for its protocol parameters; the
        // admin never has to copy keys. public_host stays admin-owned once set.
        foreach ($data['node_info'] ?? [] as $key => $value) {
            if ($value === null || $value === '' || ($key === 'public_host' && $node->public_host)) {
                continue;
            }
            $attrs[$key] = $value;
        }

        // An admin who drains or disables a node keeps it that way; health
        // only moves nodes between provisioning/online/degraded/offline.
        if (! in_array($node->status, Node::ADMIN_STATUSES, true)) {
            $attrs['status'] = $enginesDown->isNotEmpty() ? 'degraded' : 'online';
        }

        $node->update($attrs);

        if (! empty($data['net'])) {
            $this->recordTraffic($node, $data['net']);
        }
        app(NodeCapacity::class)->check($node);

        // Per-peer traffic deltas
        foreach (($data['traffic'] ?? []) as $remoteId => $bytes) {
            Peer::where('node_id', $node->id)->where('remote_id', $remoteId)
                ->update(['bytes_down' => DB::raw('bytes_down + ' . (int) $bytes)]);
        }

        if ($enginesDown->isNotEmpty()) {
            Alert::firstOrCreate(
                [
                    'source' => 'node.health',
                    'title' => "Engine down on {$node->name}: " . $enginesDown->implode(', '),
                    'acknowledged_at' => null,
                ],
                ['severity' => 'critical', 'node_id' => $node->id, 'context' => $engines]
            );
        }

        if (! empty($data['last_error'])) {
            Alert::firstOrCreate(
                [
                    'source' => 'node.sync',
                    'title' => "Agent on {$node->name} cannot apply peers",
                    'acknowledged_at' => null,
                ],
                ['severity' => 'critical', 'node_id' => $node->id, 'body' => $data['last_error']]
            );
        }

        return response()->json(['status' => 'ok', 'peer_version' => $node->peer_version]);
    }
}
