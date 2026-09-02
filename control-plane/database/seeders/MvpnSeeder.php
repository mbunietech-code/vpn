<?php

namespace Database\Seeders;

use App\Models\Node;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MvpnSeeder extends Seeder
{
    public function run(): void
    {
        // --- admin ---
        User::updateOrCreate(
            ['email' => 'admin@mbunievpn.com'],
            [
                'name' => 'MVPN Admin',
                'password' => Hash::make('change-me-now'),
                'is_admin' => true,
                'email_verified_at' => now(),
                'locale' => 'sw',
            ]
        );

        // --- plans (prices authored, not FX-derived; §A3.2) ---
        $plans = [
            ['code' => 'trial7', 'name' => 'Jaribio Siku 7', 'days' => 7,  'max_devices' => 1, 'usd' => 99,   'cny' => 700,   'sort' => 0],
            ['code' => 'm1',     'name' => 'Mwezi 1',        'days' => 30, 'max_devices' => 2, 'usd' => 399,  'cny' => 2800,  'sort' => 1],
            ['code' => 'm3',     'name' => 'Miezi 3',        'days' => 90, 'max_devices' => 3, 'usd' => 999,  'cny' => 7000,  'sort' => 2],
            ['code' => 'y1',     'name' => 'Mwaka 1',        'days' => 365,'max_devices' => 5, 'usd' => 2999, 'cny' => 21000, 'sort' => 3],
        ];

        foreach ($plans as $p) {
            Plan::updateOrCreate(['code' => $p['code']], [
                'name' => $p['name'],
                'days' => $p['days'],
                'max_devices' => $p['max_devices'],
                'data_cap_mb' => null,
                'node_scope' => null,
                'price_usd_cents' => $p['usd'],
                'price_cny_cents' => $p['cny'],
                'sort' => $p['sort'],
                'is_active' => true,
            ]);
        }

        // --- demo node (local dev only) so provisioning has a target ---
        if (app()->environment('local')) {
            Node::updateOrCreate(['public_host' => 'hk1.mbunievpn.test'], [
                'name' => 'Hong Kong 1',
                'region' => 'hk',
                'api_base' => 'https://hk1.mbunievpn.test',
                'api_secret' => 'dev-node-secret-hk1',
                'reality_pubkey' => 'DEMO_REALITY_PUBKEY_'.Str::random(20),
                'reality_short_id' => bin2hex(random_bytes(8)),
                'reality_sni' => 'www.microsoft.com',
                'hysteria_port_range' => '20000-30000',
                'hysteria_cert_sha256' => strtoupper(chunk_split(bin2hex(random_bytes(16)), 2, ':')),
                'status' => 'online',
                'last_health_at' => now(),
            ]);
        }
    }
}
