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
            ->sortBy([
                fn ($a, $b) => ($a->node->priority ?? 100) <=> ($b->node->priority ?? 100),
                fn ($a, $b) => strcmp($a->node->region, $b->node->region),
                fn ($a, $b) => strcmp($a->node->name, $b->node->name),
            ])
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
        if ($protocol === 'openvpn') {
            return $this->buildOpenVpnSingbox($sub, $platform);
        }

        $proxyOutbounds = [];   // per-peer outbound tags
        $outbounds = [];
        $nodeHosts = [];

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
                $nodeHosts[] = $node->cdn_host ?: $node->public_host;
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
                $nodeHosts[] = $node->public_host;
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
        $nodeHosts = array_values(array_unique(array_filter($nodeHosts)));
        $bootstrapDnsRules = $nodeHosts
            ? [['domain' => $nodeHosts, 'server' => 'direct-dns']]
            : [];
        $bootstrapRouteRules = $nodeHosts
            ? [['domain' => $nodeHosts, 'outbound' => 'direct']]
            : [];

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
        } else {
            $tunInbound['exclude_package'] = [
                'com.mbunie.mvpn',
                'com.android.shell',
                'com.android.settings',
                'com.google.android.gms',
            ];
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
                'rules' => $bootstrapDnsRules,
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
                    'url' => 'https://www.apple.com/library/test/success.html',
                    'interval' => '3m',
                    'tolerance' => 50,
                ],
            ], $outbounds, [
                ['type' => 'direct', 'tag' => 'direct'],
            ]),
            'route' => [
                'rules' => array_merge([
                    ['action' => 'sniff'],
                    ['protocol' => 'dns', 'action' => 'hijack-dns'],
                ], $bootstrapRouteRules, [
                    ['ip_is_private' => true, 'outbound' => 'direct'],
                ]),
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

    /** @return array<string,mixed> */
    private function buildOpenVpnSingbox(Subscription $sub, string $platform): array
    {
        $nodes = $this->activePeers($sub)
            ->map(fn ($peer) => $peer->node)
            ->filter(fn ($node) => filled($node?->openvpn_config))
            ->unique('id')
            ->values();

        $endpoints = [];
        $nodeHosts = [];

        foreach ($nodes as $node) {
            $endpoint = $this->openVpnEndpointFromProfile($node);
            if (! $endpoint) {
                continue;
            }
            $nodeHosts[] = $endpoint['server'];
            $endpoints[] = $endpoint;
        }

        if (! $endpoints) {
            return $this->buildSingbox($sub, $platform, 'reality');
        }

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
        } else {
            $tunInbound['exclude_package'] = [
                'com.mbunie.mvpn',
                'com.android.shell',
                'com.android.settings',
                'com.google.android.gms',
            ];
        }

        $nodeHosts = array_values(array_unique(array_filter($nodeHosts)));

        return [
            'log' => ['level' => 'warn', 'timestamp' => true],
            'dns' => [
                'servers' => [
                    [
                        'type' => 'openvpn',
                        'tag' => 'ovpn-dns',
                        'endpoint' => $endpoints[0]['tag'] ?? 'ovpn-client',
                        'accept_default_resolvers' => true,
                        'accept_search_domain' => true,
                    ],
                    [
                        'type' => 'https',
                        'tag' => 'direct-dns',
                        'server' => '223.5.5.5',
                        'path' => '/dns-query',
                    ],
                ],
                'rules' => [
                    [
                        'preferred_by' => 'ovpn-dns',
                        'action' => 'route',
                        'server' => 'ovpn-dns',
                    ],
                ],
                'final' => 'ovpn-dns',
                'strategy' => 'prefer_ipv4',
            ],
            'inbounds' => [$tunInbound],
            'endpoints' => $endpoints,
            'outbounds' => [
                ['type' => 'direct', 'tag' => 'direct'],
            ],
            'route' => [
                'rules' => array_merge([
                    ['action' => 'sniff'],
                    ['protocol' => 'dns', 'action' => 'hijack-dns'],
                ], $nodeHosts ? [
                    ['domain' => $nodeHosts, 'outbound' => 'direct'],
                ] : [], [
                    ['ip_is_private' => true, 'outbound' => 'direct'],
                ]),
                'final' => 'direct',
                'auto_detect_interface' => true,
            ],
            'experimental' => [
                'clash_api' => [
                    'external_controller' => '127.0.0.1:9095',
                ],
                'cache_file' => ['enabled' => true],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function openVpnEndpointFromProfile($node): ?array
    {
        $directives = $this->parseOpenVpnDirectives((string) $node->openvpn_config);
        $remote = $directives['remote'][0] ?? null;
        if (! $remote || count($remote) < 2) {
            return null;
        }

        $network = strtolower($remote[2] ?? $this->firstArg($directives, 'proto') ?? 'udp');
        $network = str_contains($network, 'tcp') ? 'tcp' : 'udp';

        $endpoint = [
            'type' => 'openvpn-client',
            'tag' => "{$node->name} · OpenVPN",
            'mode' => 'tls',
            'server' => $remote[0],
            'server_port' => (int) $remote[1],
            'network' => $network,
            'username' => (string) ($node->openvpn_username ?? ''),
            'password' => (string) ($node->openvpn_password ?? ''),
            'auth_retry' => 'nointeract',
            'redirect_gateway' => true,
            'redirect_gateway_flags' => ['def1'],
            'block_ipv6' => true,
            'tls' => [
                'server_name' => $node->openvpn_server_name ?: $remote[0],
                'server_name_type' => 'name',
            ],
        ];

        if ($auth = $this->firstArg($directives, 'auth')) {
            $endpoint['auth'] = $auth;
        }
        if ($dataCiphers = $this->firstArg($directives, 'data-ciphers')) {
            $endpoint['data_ciphers'] = array_values(array_filter(explode(':', $dataCiphers)));
        }
        if ($fallback = $this->firstArg($directives, 'data-ciphers-fallback')) {
            $endpoint['data_ciphers_fallback'] = $fallback;
        }
        if ($mss = $this->firstArg($directives, 'mssfix')) {
            $endpoint['mss_fix'] = (int) $mss;
        }
        if (($directives['remote-cert-tls'][0][0] ?? null) === 'server') {
            $endpoint['tls']['remote_certificate_tls'] = 'server';
        }
        if ($cert = $directives['<ca>'][0][0] ?? null) {
            $endpoint['tls']['certificate'] = [$cert];
        }
        if ($cert = $directives['<cert>'][0][0] ?? null) {
            $endpoint['tls']['client_certificate'] = [$cert];
        }
        if ($key = $directives['<key>'][0][0] ?? null) {
            $endpoint['tls']['client_key'] = [$key];
        }
        if ($ta = $directives['<tls-auth>'][0][0] ?? null) {
            $endpoint['tls']['control_wrap'] = [
                'type' => 'tls-auth',
                'key' => [$ta],
                'direction' => $this->firstArg($directives, 'key-direction') ?? '1',
            ];
        }
        if ($tc = $directives['<tls-crypt>'][0][0] ?? null) {
            $endpoint['tls']['control_wrap'] = [
                'type' => 'tls-crypt',
                'key' => [$tc],
            ];
        }

        return $endpoint;
    }

    /** @return array<string,array<int,array<int,string>>> */
    private function parseOpenVpnDirectives(string $profile): array
    {
        $result = [];
        $lines = preg_split('/\R/', $profile) ?: [];
        $inline = null;
        $buffer = [];

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if ($inline) {
                if ($line === '</'.$inline.'>') {
                    $result['<'.$inline.'>'][] = [implode("\n", $buffer)];
                    $inline = null;
                    $buffer = [];
                } else {
                    $buffer[] = $raw;
                }
                continue;
            }
            if (preg_match('/^<(ca|cert|key|tls-auth|tls-crypt)>$/', $line, $m)) {
                $inline = $m[1];
                $buffer = [];
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            $key = strtolower(array_shift($parts));
            $result[$key][] = $parts;
        }

        return $result;
    }

    /** @param array<string,array<int,array<int,string>>> $directives */
    private function firstArg(array $directives, string $key): ?string
    {
        return $directives[$key][0][0] ?? null;
    }
}
