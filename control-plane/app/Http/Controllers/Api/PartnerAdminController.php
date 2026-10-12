<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DeviceAdmin;
use App\Services\ManualPaymentService;
use App\Services\SubscriptionAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Day-to-day VPN management from the MbunieEduHub admin, over the signed
 * partner API (VerifyPartnerSignature). Every write names the EduHub admin
 * in `actor` and lands in the audit log. Secrets and server settings are
 * deliberately not exposed here; they stay in the VPN's own admin.
 */
class PartnerAdminController extends Controller
{
    // ---- customers & subscriptions --------------------------------------

    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->where('is_admin', false)
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('email', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%")))
            ->with(['subscriptions' => fn ($s) => $s->latest('expires_at')])
            ->withCount(['invoices'])
            ->latest('id')
            ->paginate(25);

        return $this->page($users, fn (User $u) => $this->customerRow($u));
    }

    public function customer(User $user): JsonResponse
    {
        $user->load(['subscriptions' => fn ($s) => $s->latest('expires_at')]);
        $subIds = $user->subscriptions->pluck('id');

        return response()->json([
            'customer' => $this->customerRow($user),
            'subscriptions' => $user->subscriptions->map(fn (Subscription $s) => $this->subscriptionRow($s))->values(),
            'devices' => Device::whereIn('subscription_id', $subIds)->latest('last_seen_at')->get()
                ->map(fn (Device $d) => $this->deviceRow($d))->values(),
            'invoices' => $user->invoices()->latest()->take(30)->get()
                ->map(fn (Invoice $i) => $this->invoiceRow($i))->values(),
        ]);
    }

    public function setCustomerStatus(Request $request, User $user, SubscriptionAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['nullable', 'string', 'max:200'],
            'actor' => ['required', 'string', 'max:120'],
        ]);
        abort_if($user->is_admin, 403);
        $admin->setUserStatus($user, $data['status'], $this->actor($data), $data['reason'] ?? null);

        return response()->json(['customer' => $this->customerRow($user->refresh())]);
    }

    public function grant(Request $request, User $user, SubscriptionAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'note' => ['nullable', 'string', 'max:200'],
            'actor' => ['required', 'string', 'max:120'],
        ]);
        abort_if($user->is_admin, 403);
        $sub = $admin->grant($user, Plan::where('code', $data['plan_code'])->firstOrFail(), $this->actor($data), $data['note'] ?? null);

        return response()->json(['subscription' => $this->subscriptionRow($sub)]);
    }

    public function extend(Request $request, Subscription $subscription, SubscriptionAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'note' => ['nullable', 'string', 'max:200'],
            'actor' => ['required', 'string', 'max:120'],
        ]);
        try {
            $sub = $admin->extend($subscription, (int) $data['days'], $this->actor($data), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['subscription' => $this->subscriptionRow($sub)]);
    }

    public function suspend(Request $request, Subscription $subscription, SubscriptionAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'actor' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(['subscription' => $this->subscriptionRow(
            $admin->suspend($subscription, $this->actor($data), $data['reason'] ?? null))]);
    }

    public function restore(Request $request, Subscription $subscription, SubscriptionAdmin $admin): JsonResponse
    {
        $data = $request->validate(['actor' => ['required', 'string', 'max:120']]);

        return response()->json(['subscription' => $this->subscriptionRow(
            $admin->restore($subscription, $this->actor($data)))]);
    }

    // ---- devices -------------------------------------------------------

    public function devices(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');

        $devices = Device::query()
            ->with('subscription.user')
            ->when($filter === 'online', fn (Builder $b) => $b->online()->whereNull('revoked_at'))
            ->when($filter === 'removed', fn (Builder $b) => $b->whereNotNull('revoked_at'))
            ->when($filter === 'errors', fn (Builder $b) => $b->whereNotNull('last_error')->where('last_error_at', '>', now()->subDays(2)))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('model', 'like', "%{$q}%")
                ->orWhereHas('subscription.user', fn (Builder $u) => $u
                    ->where('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))))
            ->latest('last_seen_at')
            ->paginate(25);

        return $this->page($devices, fn (Device $d) => $this->deviceRow($d));
    }

    public function removeDevice(Request $request, Device $device, DeviceAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'actor' => ['required', 'string', 'max:120'],
        ]);
        if ($device->revoked_at === null) {
            $admin->remove($device, $this->actor($data), $data['reason'] ?? null);
        }

        return response()->json(['device' => $this->deviceRow($device->refresh())]);
    }

    public function allowDevice(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['actor' => ['required', 'string', 'max:120']]);
        $device->update(['revoked_at' => null]);
        AuditLog::create([
            'actor' => $this->actor($data),
            'action' => 'device.allowed',
            'target' => "device:{$device->id}",
            'meta' => ['subscription_id' => $device->subscription_id],
        ]);

        return response()->json(['device' => $this->deviceRow($device->refresh())]);
    }

    // ---- payments ------------------------------------------------------

    public function invoices(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $invoices = Invoice::query()
            ->with('user')
            ->when($status === 'review', fn (Builder $b) => $b->whereIn('status', ['pending', 'pending_review'])->whereNotNull('proof_path'))
            ->when(in_array($status, ['pending', 'paid', 'failed'], true), fn (Builder $b) => $status === 'pending'
                ? $b->whereIn('status', ['pending', 'pending_review'])
                : $b->where('status', $status))
            ->when($q !== '', fn (Builder $b) => $b->whereHas('user', fn (Builder $u) => $u
                ->where('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->latest()
            ->paginate(25);

        return $this->page($invoices, fn (Invoice $i) => $this->invoiceRow($i));
    }

    /** The uploaded payment screenshot, for the reviewer in EduHub. */
    public function proof(Invoice $invoice): Response
    {
        $path = $invoice->proof_path ? storage_path('app/private/'.$invoice->proof_path) : null;
        abort_unless($path && is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, Invoice $invoice, ManualPaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
            'actor' => ['required', 'string', 'max:120'],
        ]);
        abort_unless(in_array($invoice->status, ['pending', 'pending_review', 'paid'], true), 422, 'This payment can no longer be approved.');
        $payments->approve($invoice, $this->actor($data), $data['note'] ?? null);

        return response()->json(['invoice' => $this->invoiceRow($invoice->refresh())]);
    }

    public function reject(Request $request, Invoice $invoice, ManualPaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
            'actor' => ['required', 'string', 'max:120'],
        ]);
        abort_unless(in_array($invoice->status, ['pending', 'pending_review'], true), 422, 'Only pending payments can be rejected.');
        $payments->reject($invoice, $this->actor($data), $data['reason']);

        return response()->json(['invoice' => $this->invoiceRow($invoice->refresh())]);
    }

    // ---- plans ---------------------------------------------------------

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('days')->get()->map(fn (Plan $p) => $this->planRow($p))->values()]);
    }

    /** Prices arrive in normal units (e.g. 8.00 USD, 55 CNY, 20000 TZS). */
    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'max_devices' => ['required', 'integer', 'min:1', 'max:20'],
            'price_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
            'price_cny' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'price_tzs' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'is_active' => ['required', 'boolean'],
            'actor' => ['required', 'string', 'max:120'],
        ]);

        $before = $this->planRow($plan);
        $plan->update([
            'name' => $data['name'],
            'days' => (int) $data['days'],
            'max_devices' => (int) $data['max_devices'],
            'price_usd_cents' => (int) round($data['price_usd'] * 100),
            'price_cny_cents' => (int) round($data['price_cny'] * 100),
            'price_tzs_cents' => (int) round($data['price_tzs'] * 100),
            'is_active' => (bool) $data['is_active'],
        ]);
        AuditLog::create([
            'actor' => $this->actor($data),
            'action' => 'plan.updated',
            'target' => "plan:{$plan->code}",
            'meta' => ['before' => $before, 'after' => $this->planRow($plan)],
        ]);

        return response()->json(['plan' => $this->planRow($plan)]);
    }

    // ---- shapes --------------------------------------------------------

    private function actor(array $data): string
    {
        return 'eduhub:'.$data['actor'];
    }

    private function page(LengthAwarePaginator $p, callable $map): JsonResponse
    {
        return response()->json([
            'data' => collect($p->items())->map($map)->values(),
            'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ]);
    }

    private function customerRow(User $u): array
    {
        $sub = $u->relationLoaded('subscriptions') ? $u->subscriptions->first() : $u->subscriptions()->latest('expires_at')->first();

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'status' => $u->status ?? 'active',
            'eduhub_user_id' => $u->eduhub_user_id,
            'created_at' => $u->created_at?->toIso8601String(),
            'subscription' => $sub ? $this->subscriptionRow($sub) : null,
        ];
    }

    private function subscriptionRow(Subscription $s): array
    {
        $live = $s->status === 'active' && $s->expires_at?->isFuture();

        return [
            'id' => $s->id,
            'plan' => $s->plan_code,
            'status' => $live ? 'active' : ($s->status === 'active' ? 'expired' : $s->status),
            'started_at' => $s->started_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'max_devices' => $s->max_devices,
            'devices' => $s->devices()->whereNull('revoked_at')->count(),
            'online' => $s->devices()->online()->whereNull('revoked_at')->exists(),
        ];
    }

    private function deviceRow(Device $d): array
    {
        $user = $d->subscription?->user;

        return [
            'id' => $d->id,
            'customer' => $user ? ['id' => $user->id, 'email' => $user->email, 'phone' => $user->phone] : null,
            'platform' => $d->platform,
            'model' => $d->model,
            'os_version' => $d->os_version,
            'app_version' => $d->app_version,
            'protocol' => $d->protocol,
            'state' => $d->revoked_at ? 'removed' : ($d->isOnline() ? 'online' : 'offline'),
            'connected_at' => $d->connected_at?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'last_error' => $d->last_error,
            'last_error_at' => $d->last_error_at?->toIso8601String(),
        ];
    }

    private function invoiceRow(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'customer' => $i->user ? ['id' => $i->user->id, 'email' => $i->user->email, 'phone' => $i->user->phone] : null,
            'plan' => $i->plan_code,
            'amount_cents' => $i->amount_cents,
            'currency' => $i->currency,
            'provider' => $i->provider,
            'method' => $i->payment_method,
            'status' => $i->status,
            'has_proof' => (bool) $i->proof_path,
            'user_note' => $i->meta['user_note'] ?? null,
            'review_note' => $i->review_note,
            'reviewed_by' => $i->reviewed_by,
            'paid_at' => $i->paid_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    private function planRow(Plan $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'days' => $p->days,
            'max_devices' => $p->max_devices,
            'price_usd' => $p->price_usd_cents / 100,
            'price_cny' => $p->price_cny_cents / 100,
            'price_tzs' => $p->price_tzs_cents / 100,
            'is_active' => (bool) $p->is_active,
        ];
    }
}
