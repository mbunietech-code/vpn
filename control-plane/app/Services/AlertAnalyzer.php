<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Node;
use Illuminate\Support\Facades\Http;

/**
 * AI anomaly alerting (05-Addendum-MVPN.md §A5.4).
 *
 * Rule-based detection always runs. When ANTHROPIC_API_KEY is set, each new
 * incident is also sent to Claude for severity classification + a one-line
 * action suggestion. Best-effort: raw alerts still fire if the API is down.
 */
class AlertAnalyzer
{
    /** Scan node/system state and raise alerts for anything anomalous. */
    public function scan(): void
    {
        foreach (Node::all() as $node) {
            $this->checkNode($node);
        }
    }

    private function checkNode(Node $node): void
    {
        // Node silent for > 5 min
        if ($node->last_health_at && $node->last_health_at->lt(now()->subMinutes(5)) && $node->status !== 'offline') {
            $node->update(['status' => 'offline']);
            $this->raise('critical', 'node.health', "Node {$node->name} stopped reporting",
                "No health report since {$node->last_health_at->diffForHumans()}.", $node);
        }

        // TLS / REALITY cert age not tracked here yet - placeholder hook.

        $health = $node->health ?? [];
        $engines = $health['engines'] ?? [];
        foreach ($engines as $name => $state) {
            if ($state !== 'up') {
                $this->raise('critical', 'node.health', "Engine '{$name}' down on {$node->name}",
                    'Restart the systemd unit and check logs.', $node);
            }
        }

        // Applied peer version lagging the desired version
        $applied = $health['applied_version'] ?? null;
        if ($applied !== null && $applied < $node->peer_version - 1) {
            $this->raise('warn', 'node.sync', "Node {$node->name} peer list is stale",
                "Agent applied v{$applied}, control plane is at v{$node->peer_version}.", $node);
        }
    }

    public function raise(string $severity, string $source, string $title, string $body, ?Node $node = null, array $context = []): Alert
    {
        $alert = Alert::firstOrCreate(
            ['source' => $source, 'title' => $title, 'acknowledged_at' => null],
            ['severity' => $severity, 'body' => $body, 'node_id' => $node?->id, 'context' => $context],
        );

        if ($alert->wasRecentlyCreated) {
            $this->enrichWithAi($alert);
            $this->notify($alert);
        }

        return $alert;
    }

    private function enrichWithAi(Alert $alert): void
    {
        $key = config('services.anthropic.key');
        if (! $key) {
            return;
        }

        try {
            $resp = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.alert_model'),
                'max_tokens' => 300,
                'system' => 'You are an SRE assistant for a small VPN service. Given one incident, reply with STRICT JSON: '
                    . '{"severity":"info|warn|critical","summary":"<=200 chars","action":"<=200 chars"}.',
                'messages' => [[
                    'role' => 'user',
                    'content' => json_encode([
                        'title' => $alert->title,
                        'body' => $alert->body,
                        'source' => $alert->source,
                        'context' => $alert->context,
                    ]),
                ]],
            ])->json();

            $text = $resp['content'][0]['text'] ?? null;
            if ($text && ($parsed = json_decode($text, true))) {
                $alert->update([
                    'severity' => in_array($parsed['severity'] ?? '', ['info', 'warn', 'critical'], true)
                        ? $parsed['severity'] : $alert->severity,
                    'ai_summary' => $parsed['summary'] ?? null,
                    'ai_action' => $parsed['action'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notify(Alert $alert): void
    {
        if ($alert->severity !== 'critical') {
            return;
        }

        $token = config('services.telegram.bot_token');
        $chat = config('services.telegram.chat_id');
        if (! $token || ! $chat) {
            return;
        }

        $msg = "🚨 *MVPN {$alert->severity}*\n{$alert->title}\n\n"
            . ($alert->ai_summary ?: $alert->body) . "\n"
            . ($alert->ai_action ? "\n_Action:_ {$alert->ai_action}" : '');

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => $msg,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
