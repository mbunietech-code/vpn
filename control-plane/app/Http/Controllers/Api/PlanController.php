<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->orderBy('sort')->get();

        $hasTzs = $plans->contains(fn (Plan $p) => $p->price_tzs_cents > 0);

        $currencies = array_values(array_filter([
            ...config('services.mvpn.currencies'),
            $hasTzs ? 'tzs' : null,
        ]));

        return response()->json([
            'currencies' => $currencies,
            'default_currency' => config('services.mvpn.default_currency'),
            'plans' => $plans->map(fn (Plan $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'days' => $p->days,
                'max_devices' => $p->max_devices,
                'data_cap_mb' => $p->data_cap_mb,
                'price' => [
                    'usd_cents' => $p->price_usd_cents,
                    'cny_cents' => $p->price_cny_cents,
                    'tzs_cents' => $p->price_tzs_cents,
                    'display' => $p->display(),
                ],
            ]),
        ]);
    }
}
