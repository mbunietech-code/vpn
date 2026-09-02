<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
            'code' => $p->code,
            'name' => $p->name,
            'days' => $p->days,
            'max_devices' => $p->max_devices,
            'data_cap_mb' => $p->data_cap_mb,
            'price' => [
                'usd_cents' => $p->price_usd_cents,
                'cny_cents' => $p->price_cny_cents,
                'display' => $p->display(), // { "usd": "$3.99", "cny": "¥28" }
            ],
        ]);

        return response()->json([
            'currencies' => config('services.mvpn.currencies'),
            'default_currency' => config('services.mvpn.default_currency'),
            'plans' => $plans,
        ]);
    }
}
