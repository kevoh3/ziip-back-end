<?php

namespace App\Http\Controllers\Api\Callbacks;

use App\Events\ChoiceBank\BulkTransferResult;
use App\Events\ChoiceBank\FxResult;
use App\Events\ChoiceBank\MerchantPayIn;
use App\Events\ChoiceBank\MerchantPayout;
use App\Events\ChoiceBank\OnboardingCompleted;
use App\Events\ChoiceBank\PayInReceived;
use App\Events\ChoiceBank\PayoutResult;
use App\Events\ChoiceBank\StatementReady;
use App\Support\ChoiceSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives and dispatches all ChoiceBank webhook callbacks.
 *
 * ChoiceBank sends event code in payload['data']['eventCode'].
 *
 * Event codes:
 *   0001 — Personal onboarding result
 *   0002 — Outbound transfer (payout) result
 *   0003 — Inbound transfer (pay-in) received
 *   0004 — Merchant pay-in received
 *   0008 — Bulk transfer result
 *   0009 — Account statement ready
 *   0013 — Foreign exchange result
 *   0015 — File / job ready
 *   0022 — Merchant payout result
 */
class ChoiceBankCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $secret  = config('services.choice.webhook_secret');

        // --- 1. Require signature fields ---
        if (!isset($payload['signature'], $payload['salt'])) {
            Log::warning('ChoiceBank callback: missing signature fields', [
                'ip'   => $request->ip(),
                'keys' => array_keys($payload),
            ]);

            return response()->json(['message' => 'Missing signature'], Response::HTTP_UNAUTHORIZED);
        }

        // --- 2. Verify signature ---
        if (!$secret || !ChoiceSigner::verify($payload, $secret)) {
            Log::warning('ChoiceBank callback: invalid signature', [
                'ip'        => $request->ip(),
                'sender'    => $payload['sender'] ?? null,
                'requestId' => $payload['requestId'] ?? null,
            ]);

            return response()->json(['message' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        // --- 3. Extract event info ---
        $data      = $payload['data'] ?? [];
        $eventCode = (string) ($data['eventCode'] ?? '');
        $requestId = $payload['requestId'] ?? null;

        Log::info('ChoiceBank callback received', [
            'requestId' => $requestId,
            'eventCode' => $eventCode,
            'sender'    => $payload['sender'] ?? null,
        ]);

        // --- 4. Dispatch typed event ---
        $this->dispatch($eventCode, $data, $payload);

        // ChoiceBank expects a 200 OK — always respond quickly
        return response()->json(['message' => 'OK'], Response::HTTP_OK);
    }

    /**
     * Dispatch the appropriate Laravel event based on ChoiceBank event code.
     */
    private function dispatch(string $eventCode, array $data, array $rawPayload): void
    {
        match ($eventCode) {
            '0001' => OnboardingCompleted::dispatch($data, $rawPayload),
            '0002' => PayoutResult::dispatch($data, $rawPayload),
            '0003' => PayInReceived::dispatch($data, $rawPayload),
            '0004' => MerchantPayIn::dispatch($data, $rawPayload),
            '0008' => BulkTransferResult::dispatch($data, $rawPayload),
            '0009' => StatementReady::dispatch($data, $rawPayload),
            '0013' => FxResult::dispatch($data, $rawPayload),
            '0022' => MerchantPayout::dispatch($data, $rawPayload),
            default => Log::notice('ChoiceBank callback: unhandled event code', [
                'eventCode' => $eventCode,
                'data'      => $data,
            ]),
        };
    }

    /** Simple endpoint to confirm the callback URL is reachable. */
    public function verify(): JsonResponse
    {
        return response()->json(['message' => 'ChoiceBank callback endpoint OK']);
    }
}
