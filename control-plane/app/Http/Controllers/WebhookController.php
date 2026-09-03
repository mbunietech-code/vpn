<?php

namespace App\Http\Controllers;

use App\Services\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private WebhookProcessor $processor) {}

    public function stripe(Request $request): JsonResponse
    {
        $r = $this->processor->handle('stripe', $request);

        return response()->json($r['body'], $r['status']);
    }

    public function cryptomus(Request $request): JsonResponse
    {
        $r = $this->processor->handle('cryptomus', $request);

        return response()->json($r['body'], $r['status']);
    }

    public function clickpesa(Request $request): JsonResponse
    {
        $r = $this->processor->handle('clickpesa', $request);

        return response()->json($r['body'], $r['status']);
    }
}
