<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kick an unknown device out for real. VPN keys belong to the subscription,
 * not the device, so blocking the device alone would leave a copied config
 * working. This also signs the customer out everywhere and rotates the
 * subscription's link and VPN keys; the genuine owner signs in again with an
 * email/SMS code and gets fresh keys automatically, the removed device can't.
 */
class DeviceAdmin
{
    public function remove(Device $device, string $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($device, $actor, $reason) {
            $device->update(['revoked_at' => now(), 'vpn_connected' => false]);

            $sub = $device->subscription;
            $user = $sub?->user;

            // Sign out every app session of this customer.
            $user?->tokens()->delete();

            if ($sub) {
                // New subscription link: the old /sub/{token} stops working.
                $sub->rotateToken();

                // New VPN credentials on every node.
                $nodeIds = collect();
                foreach ($sub->peers as $peer) {
                    $peer->update([
                        'remote_id' => (string) Str::uuid(),
                        'secret' => $peer->protocol === 'hysteria2' ? Str::random(24) : $peer->secret,
                    ]);
                    $nodeIds->push($peer->node_id);
                }
                Node::whereIn('id', $nodeIds->unique())->get()->each->bumpPeerVersion();
            }

            AuditLog::create([
                'actor' => $actor,
                'action' => 'device.removed',
                'target' => "device:{$device->id}",
                'meta' => array_filter([
                    'subscription_id' => $sub?->id,
                    'model' => $device->model,
                    'platform' => $device->platform,
                    'reason' => $reason,
                ]),
            ]);
        });
    }
}
