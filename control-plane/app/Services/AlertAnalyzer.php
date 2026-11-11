<?php

namespace App\Services;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        if ($node->last_health_at && $node->last_health_at->lt(now()->subMinutes(5))
            && ! in_array($node->status, ['offline', ...Node::ADMIN_STATUSES], true)) {
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

    /**
     * The one way to raise an alert: an open alert with the same source and
     * title is reused (no repeats), a new one is analysed and the admins are told.
     */
    public function raise(string $severity, string $source, string $title, ?string $body = null, ?Node $node = null, array $context = []): Alert
    {
        $alert = Alert::firstOrCreate(
            ['source' => $source, 'title' => $title, 'acknowledged_at' => null],
            ['severity' => $severity, 'body' => $body, 'node_id' => $node?->id, 'context' => $context],
        );

        if ($alert->wasRecentlyCreated) {
            $this->enrichWithAi($alert);
            try {
                $this->notify($alert->refresh());
            } catch (\Throwable $e) {
                report($e); // telling the admins must never break the caller (a node report, a webhook)
            }
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

    /**
     * Tell the admins: email for warnings and critical alerts (to the
     * Notifications list, else every admin), Telegram for critical ones when
     * a bot is set.
     *
     * @return array{email: list<string>, failed: list<string>, telegram: bool|null}
     */
    public function notify(Alert $alert): array
    {
        $result = ['email' => [], 'failed' => [], 'telegram' => null];

        if (in_array($alert->severity, ['warn', 'critical'], true)) {
            foreach (app(PaymentAlerts::class)->recipients() as $to) {
                try {
                    app(Mailer::class)->send($to, new AlertMail($alert));
                    $result['email'][] = $to;
                } catch (\Throwable $e) {
                    $result['failed'][] = $to;
                    Log::warning('Alert email failed', ['to' => $to, 'alert' => $alert->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($alert->severity === 'critical') {
            $result['telegram'] = $this->telegram($alert);
        }

        return $result;
    }

    /** null = Telegram not set up; true/false = sent or not. */
    private function telegram(Alert $alert): ?bool
    {
        $token = config('services.telegram.bot_token');
        $chat = config('services.telegram.chat_id');
        if (! $token || ! $chat) {
            return null;
        }

        // HTML, escaped: Markdown broke on names with _ or * and the message was dropped.
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $msg = "🚨 <b>Mbunie VPN {$e($alert->severity)}</b>\n{$e($alert->title)}\n\n"
            .$e($alert->ai_summary ?: $alert->body)
            .($alert->ai_action ? "\n\n<i>Action:</i> {$e($alert->ai_action)}" : '');

        try {
            $resp = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
            if ($resp->failed()) {
                Log::warning('Alert Telegram failed', ['alert' => $alert->id, 'status' => $resp->status(), 'body' => $resp->body()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
