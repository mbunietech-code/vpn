<?php

namespace App\Services;

use App\Models\Subscription;

/**
 * Builds the client subscription payload for /sub/{token}.
 *
 * Output: base64 of newline-separated share links (vless:// + hysteria2://),
 * the format Hiddify / sing-box / v2rayN all import. The token is opaque and
 * reveals no user identity (FR-NEW-05).
 */
class SubscriptionBuilder
{
    public function build(Subscription $sub): string
    {
        $links = [];

        $peers = $sub->peers()
            ->where('status', 'active')
            ->with('node')
            ->get()
            ->filter(fn ($p) => $p->node && $p->node->isUsable());

        foreach ($peers as $peer) {
            $node = $peer->node;
            $tag = rawurlencode("MVPN {$node->name}");

            if ($peer->protocol === 'vless-reality') {
                $host = $node->cdn_host ?: $node->public_host;
                $query = http_build_query([
                    'type' => 'tcp',
                    'security' => 'reality',
                    'sni' => $node->reality_sni,
                    'fp' => 'chrome',
                    'pbk' => $node->reality_pubkey,
                    'sid' => $node->reality_short_id,
                    'flow' => 'xtls-rprx-vision',
                    'encryption' => 'none',
                ]);
                $links[] = "vless://{$peer->remote_id}@{$host}:443?{$query}#{$tag}";
            }

            if ($peer->protocol === 'hysteria2') {
                $query = http_build_query([
                    'sni' => $node->reality_sni,
                    'insecure' => 1,
                    'pinSHA256' => $node->hysteria_cert_sha256,
                    'mport' => $node->hysteria_port_range,
                ]);
                $links[] = "hysteria2://{$peer->secret}@{$node->public_host}:443?{$query}#{$tag}";
            }
        }

        return base64_encode(implode("\n", $links));
    }
}
