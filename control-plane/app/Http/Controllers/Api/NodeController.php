<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Peer;
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
                'status' => 'active',
            ])->values();

        // The agent re-applies only when `version` changes. Fold the actual
        // active set into it so an expiry takes effect on the next sync even
        // if nothing bumped peer_version (e.g. the sweep cron isn't running).
        $fingerprint = $peers->map(fn ($p) => $p['protocol'] . ':' . $p['remote_id'])->sort()->implode(',');

        return response()->json([
            'version' => (int) sprintf('%u', crc32($node->peer_version . '|' . $fingerprint)),
            'peers' => $peers,
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
