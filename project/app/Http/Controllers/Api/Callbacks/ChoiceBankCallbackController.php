<?php

namespace App\Http\Controllers\Api\Callbacks;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ChoiceBankCallbackController extends Controller
{
    public function handle(Request $request)
    {
        // If Choice truly sends signature in a header:
        $signature = $request->header('X-ChoiceBank-Signature');

        // ✅ FIX: read from services.choice.webhook_secret (matches your config/services.php)
        $secret = config('services.choice.webhook_secret');

        if (!$this->isValidSignature($request->getContent(), $signature, $secret)) {
            Log::warning('ChoiceBank callback: invalid signature', [
                'ip' => $request->ip(),
                'has_signature' => (bool) $signature,
                'has_secret' => (bool) $secret,
            ]);

            return response()->json(
                ['message' => 'Invalid signature'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $payload = $request->all();

        // "event_id" / "reference" are placeholders — keep for now
        $eventId = $payload['event_id'] ?? $payload['reference'] ?? data_get($payload, 'data.reference') ?? null;

        Log::info('ChoiceBank callback received', [
            'event_id' => $eventId,
            'payload' => $payload,
        ]);

        return response()->json(['message' => 'OK'], Response::HTTP_OK);
    }

    public function verify(Request $request)
    {
        return response()->json(['message' => 'ChoiceBank callback endpoint OK']);
    }

    // ✅ FIX: allow null secret without crashing
    private function isValidSignature(string $rawBody, ?string $signature, ?string $secret): bool
    {
        if (!$signature || !$secret) return false;

        // Common pattern: hex hmac sha256
        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $signature);
    }
}
