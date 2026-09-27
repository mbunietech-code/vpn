<?php

namespace App\Services;

use App\Models\Subscription;

/**
 * Builds the client subscription payload for /sub/{token}.
 *
 *  - build()        → base64 of vless:// / hysteria2:// share links
 *                     (Hiddify / v2rayN / stock sing-box import).
 *  - buildSingbox() → a complete sing-box client config the MVPN desktop
 *                     engine runs directly (`sing-box run -c`).
 *
 * The token is opaque and reveals no user identity (FR-NEW-05).
 */
class SubscriptionBuilder
{
    /** @return \Illuminate\Support\Collection<int,\App\Models\Peer> */
    private function activePeers(Subscription $sub)
    {
        return $sub->peers()
            ->where('status', 'active')
            ->with('node')
            ->get()
            ->filter(fn ($p) => $p->node && $p->node->isUsable())
            ->values();
    }

    public function build(Subscription $sub): string
    {
        $links = [];

        foreach ($this->activePeers($sub) as $peer) {
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
                $links[] = "vless://{$peer->remote_id}@{$host}:{$node->reality_port}?{$query}#{$tag}";
            }

            if ($peer->protocol === 'hysteria2') {
                $query = http_build_query([
                    'sni' => $node->reality_sni,
                    'insecure' => 1,
                    'pinSHA256' => $node->hysteria_cert_sha256,
                    'mport' => $node->hysteria_port_range,
                ]);
                $links[] = "hysteria2://{$peer->secret}@{$node->public_host}:{$node->hysteria_port}?{$query}#{$tag}";
            }
        }

        return base64_encode(implode("\n", $links));
    }

    /**
     * Full sing-box client config. `stack` differs per platform:
     *   windows/linux/android → "mixed",  macos → "system".
     *
     * @return array<string,mixed>
     */
    public function buildSingbox(Subscription $sub, string $platform = 'windows', string $protocol = 'auto'): array
    {
        $proxyOutbounds = [];   // per-peer outbound tags
        $outbounds = [];

        foreach ($this->activePeers($sub) as $peer) {
            $node = $peer->node;

            // Honour an explicit protocol preference from the client.
            if ($protocol === 'reality' && $peer->protocol !== 'vless-reality') {
                continue;
            }
            if ($protocol === 'hysteria2' && $peer->protocol !== 'hysteria2') {
                continue;
            }

            if ($peer->protocol === 'vless-reality') {
                $tag = "{$node->name} · REALITY";
                $proxyOutbounds[] = $tag;
                $outbounds[] = [
                    'type' => 'vless',
                    'tag' => $tag,
                    'server' => $node->cdn_host ?: $node->public_host,
                    'server_port' => (int) $node->reality_port,
                    'uuid' => $peer->remote_id,
                    'flow' => 'xtls-rprx-vision',
                    'packet_encoding' => 'xudp',
                    'tls' => [
                        'enabled' => true,
                        'server_name' => $node->reality_sni,
                        'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                        'reality' => [
                            'enabled' => true,
                            'public_key' => $node->reality_pubkey,
                            'short_id' => $node->reality_short_id,
                        ],
                    ],
                ];
            }

            if ($peer->protocol === 'hysteria2') {
                $tag = "{$node->name} · Hysteria2";
                $proxyOutbounds[] = $tag;
                $hy = [
                    'type' => 'hysteria2',
                    'tag' => $tag,
                    'server' => $node->public_host,
                    'server_port' => (int) $node->hysteria_port,
                    'password' => $peer->secret,
                    'tls' => [
                        'enabled' => true,
                        'server_name' => $node->reality_sni,
                        'insecure' => true,
                    ],
                ];
                if ($node->hysteria_port_range) {
                    $hy['server_ports'] = [str_replace('-', ':', $node->hysteria_port_range)];
                }
                if ($node->hysteria_cert_sha256) {
                    $hy['tls']['certificate'] = [];
                }
                $outbounds[] = $hy;
            }
        }

        // Selector + auto (urltest) sit in front of the peers.
        $selectorMembers = array_merge(['auto'], $proxyOutbounds);

        $tunInbound = [
            'type' => 'tun',
            'tag' => 'tun-in',
            'address' => ['172.19.0.1/30', 'fdfe:dcba:9876::1/126'],
            'auto_route' => true,
            'route_exclude_address' => [
                '10.0.0.0/8',
                '100.64.0.0/10',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '224.0.0.0/4',
                '255.255.255.255/32',
                '::1/128',
                'fc00::/7',
                'fe80::/10',
                'ff00::/8',
            ],
            'stack' => $platform === 'macos' ? 'system' : 'mixed',
        ];

        if ($platform !== 'android') {
            $tunInbound['interface_name'] = 'mvpn0';
            $tunInbound['strict_route'] = true;
        }

        $config = [
            'log' => ['level' => 'warn', 'timestamp' => true],
            'dns' => [
                'servers' => [
                    [
                        'type' => 'https',
                        'tag' => 'proxy-dns',
                        'server' => '1.1.1.1',
                        'path' => '/dns-query',
                        'detour' => 'proxy',
                    ],
                    [
                        'type' => 'https',
                        'tag' => 'direct-dns',
                        'server' => '223.5.5.5',
                        'path' => '/dns-query',
                    ],
                ],
                'rules' => [
                    ['domain_suffix' => ['.cn'], 'server' => 'direct-dns'],
                ],
                'final' => 'proxy-dns',
                'strategy' => 'prefer_ipv4',
            ],
            'inbounds' => [$tunInbound],
            'outbounds' => array_merge([
                [
                    'type' => 'selector',
                    'tag' => 'proxy',
                    'outbounds' => $selectorMembers,
                    'default' => 'auto',
                ],
                [
                    'type' => 'urltest',
                    'tag' => 'auto',
                    'outbounds' => $proxyOutbounds ?: ['direct'],
                    'url' => 'https://www.gstatic.com/generate_204',
                    'interval' => '3m',
                    'tolerance' => 50,
                ],
            ], $outbounds, [
                ['type' => 'direct', 'tag' => 'direct'],
            ]),
            'route' => [
                'rules' => [
                    ['action' => 'sniff'],
                    ['protocol' => 'dns', 'action' => 'hijack-dns'],
                    ['ip_is_private' => true, 'outbound' => 'direct'],
                    ['domain_suffix' => ['.cn'], 'outbound' => 'direct'],
                ],
                'final' => 'proxy',
                'auto_detect_interface' => true,
            ],
            'experimental' => [
                'clash_api' => [
                    'external_controller' => '127.0.0.1:9095',
                ],
                'cache_file' => ['enabled' => true],
            ],
        ];

        return $config;
    }
}
