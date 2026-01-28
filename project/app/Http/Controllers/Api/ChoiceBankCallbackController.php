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
        // 1) Verify authenticity (recommended)
        // Example: HMAC signature header (adjust to ChoiceBank docs)
        $signature = $request->header('X-ChoiceBank-Signature');
        $secret = config('services.choicebank.webhook_secret');

        if (!$this->isValidSignature($request->getContent(), $signature, $secret)) {
            Log::warning('ChoiceBank callback: invalid signature', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        // 2) Idempotency (avoid double-processing)
        // Example: use event/reference id from payload
        $payload = $request->all();
        $eventId = $payload['event_id'] ?? $payload['reference'] ?? null;

        Log::info('ChoiceBank callback received', [
            'event_id' => $eventId,
            'payload' => $payload,
        ]);

        // 3) Process based on event type/status
        // e.g. deposit success, reversal, etc...
        // TODO: locate your transaction by reference, update status, record raw payload, etc.

        return response()->json(['message' => 'OK'], Response::HTTP_OK);
    }

    public function verify(Request $request)
    {
        // Optional verification handshake (only if ChoiceBank requires it)
        return response()->json(['message' => 'ChoiceBank callback endpoint OK']);
    }

    private function isValidSignature(string $rawBody, ?string $signature, string $secret): bool
    {
        if (!$signature || !$secret) return false;

        // Common pattern: hex hmac sha256
        $computed = hash_hmac('sha256', $rawBody, $secret);

        // timing-safe compare
        return hash_equals($computed, $signature);
    }
}
