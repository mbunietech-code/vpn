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

    /** Register / refresh this device against the device limit (FR-NEW-08). */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'max:128'],
            'platform' => ['nullable', 'in:android,windows,macos,linux'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $sub = $request->user()->activeSubscription();
        if (! $sub) {
            return response()->json(['error' => 'no_active_subscription'], 403);
        }

        $device = $sub->devices()->firstOrNew(['fingerprint' => $data['fingerprint']]);

        if (! $device->exists) {
            $activeCount = $sub->devices()->whereNull('revoked_at')->count();
            if ($activeCount >= $sub->max_devices) {
                return response()->json([
                    'error' => 'device_limit_reached',
                    'limit' => $sub->max_devices,
                ], 409);
            }
        }

        $device->fill([
            'platform' => $data['platform'] ?? $device->platform,
            'name' => $data['name'] ?? $device->name,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ])->save();

        return response()->json(['status' => 'ok', 'device_id' => $device->id]);
    }
}
