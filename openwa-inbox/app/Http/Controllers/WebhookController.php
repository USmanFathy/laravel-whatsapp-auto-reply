<?php

namespace App\Http\Controllers;

use App\Services\WebhookProcessor;
use App\Support\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        $secret = (string) config('openwa.webhook_secret');
        if ($secret === '') {
            Log::error('openwa.webhook rejected: OPENWA_WEBHOOK_SECRET is not configured', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Webhook secret is not configured.'], 503);
        }

        // The signature covers the exact raw bytes OpenWA sent.
        $raw = $request->getContent();
        if (! WebhookSignature::verify($raw, $request->header('X-OpenWA-Signature'), $secret)) {
            Log::warning('openwa.webhook rejected: invalid or missing X-OpenWA-Signature (secret mismatch?)', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_string($payload['event'] ?? null)) {
            Log::warning('openwa.webhook rejected: signed body is not an OpenWA event');

            return response()->json(['error' => 'Malformed payload.'], 400);
        }

        $key = $request->header('X-OpenWA-Idempotency-Key') ?: hash('sha256', $raw);

        $outcome = $processor->process($payload, $key, $request->header('X-OpenWA-Delivery-Id'));

        return response()->json(['status' => $outcome]);
    }
}
