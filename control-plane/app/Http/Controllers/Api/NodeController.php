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

        return response()->json([
            'version' => $node->peer_version,
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
            'applied_version' => ['nullable', 'integer'],
            'uptime_seconds' => ['nullable', 'integer'],
            'active_peers' => ['nullable', 'integer'],
            'engines' => ['nullable', 'array'],
            'traffic' => ['nullable', 'array'],
        ]);

        $engines = $data['engines'] ?? [];
        $enginesDown = collect($engines)->filter(fn ($s) => $s !== 'up')->keys();

        $node->update([
            'status' => $enginesDown->isNotEmpty() ? 'degraded' : 'online',
            'health' => [
                'uptime_seconds' => $data['uptime_seconds'] ?? null,
                'active_peers' => $data['active_peers'] ?? null,
                'engines' => $engines,
                'applied_version' => $data['applied_version'] ?? null,
            ],
            'last_health_at' => now(),
        ]);

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

        return response()->json(['status' => 'ok', 'peer_version' => $node->peer_version]);
    }
}
