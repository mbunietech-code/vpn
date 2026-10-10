<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /** App polls this after checkout to detect auto-activation. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->activeSubscription()
            ?? $user->subscriptions()->latest()->first();

        $pending = $user->invoices()
            ->whereIn('status', ['pending', 'pending_review'])
            ->where('expires_at', '>', now())
            ->latest()->first();

        $pendingBlock = $pending ? [
            'invoice_id' => $pending->id,
            'plan' => $pending->plan_code,
            'status' => $pending->status,              // pending | pending_review
            'proof_uploaded' => (bool) $pending->proof_path,
            'amount' => $pending->amount_cents,
            'currency' => $pending->currency,
        ] : null;

        if (! $sub) {
            return response()->json([
                'status' => $pending ? 'pending' : 'none',
                'pending_invoice' => $pendingBlock,
                'awaiting_payment' => (bool) $pending,
            ]);
        }

        return response()->json([
            'status' => $sub->status,                  // pending | active | expired | suspended
            'plan' => $sub->plan_code,
            'expires_at' => $sub->expires_at?->toIso8601String(),
            'max_devices' => $sub->max_devices,
            'data_used_mb' => $sub->data_used_mb,
            'sub_url' => $sub->isActive()
                ? url("/sub/{$sub->sub_token}")
                : null,
            'pending_invoice' => $pendingBlock,
            'awaiting_payment' => (bool) $pending,
        ]);
    }

    /**
     * Register / refresh this device against the device limit (FR-NEW-08).
     * The app also calls this as a heartbeat (every ~2 min while the tunnel
     * is up, and once on connect/disconnect) so the admin can see who is
     * online right now.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'max:128'],
            'platform' => ['nullable', 'in:android,windows,macos,linux,ios'],
            'name' => ['nullable', 'string', 'max:80'],
            'connected' => ['nullable', 'boolean'],
            'protocol' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $sub = $request->user()->activeSubscription();
        if (! $sub) {
            return response()->json(['error' => 'no_active_subscription'], 403);
        }

        $device = $sub->devices()->firstOrNew(['fingerprint' => $data['fingerprint']]);

        // An admin-revoked device stays revoked; it must not lift its own block.
        if ($device->exists && $device->revoked_at !== null) {
            return response()->json(['error' => 'device_revoked'], 403);
        }

        $overLimit = ! $device->exists
            && $sub->devices()->whereNull('revoked_at')->count() >= $sub->max_devices;

        $connected = (bool) ($data['connected'] ?? false);
        $device->fill([
            'platform' => $data['platform'] ?? $device->platform,
            'name' => $data['name'] ?? $device->name,
            'app_version' => $data['app_version'] ?? $device->app_version,
            'last_seen_at' => now(),
            'vpn_connected' => $connected,
            'protocol' => $connected ? ($data['protocol'] ?? $device->protocol) : $device->protocol,
            'connected_at' => $connected ? ($device->vpn_connected ? $device->connected_at : now()) : null,
        ])->save();

        // Recorded either way so the admin sees it; the limit is reported,
        // not yet enforced by the client.
        if ($overLimit) {
            return response()->json([
                'error' => 'device_limit_reached',
                'limit' => $sub->max_devices,
                'device_id' => $device->id,
            ], 409);
        }

        return response()->json(['status' => 'ok', 'device_id' => $device->id]);
    }
}
