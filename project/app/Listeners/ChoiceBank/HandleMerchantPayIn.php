<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\MerchantPayIn;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0004 — Merchant pay-in received.
 *
 * A customer paid a merchant via their ChoiceBank shortcode/account.
 * Find the merchant wallet, credit it, create a Transaction record.
 */
class HandleMerchantPayIn implements ShouldQueue
{
    public function handle(MerchantPayIn $event): void
    {
        $data      = $event->data;
        $accountId = $data['merchantAccountId'] ?? $data['accountId'] ?? $data['payeeAccountId'] ?? null;
        $txId      = $data['txId'] ?? null;
        $amount    = (float) ($data['amount'] ?? 0);

        if (!$accountId || $amount <= 0) {
            Log::warning('HandleMerchantPayIn: invalid payload', $data);
            return;
        }

        // Find the merchant wallet (user_type=2)
        $providerMatch = function ($q) {
            $q->where('wallet_external_provider', 'ChoiceBank BaaS')
              ->orWhere('wallet_external_provider', 'choicebank');
        };

        $wallet = Wallet::where('wallet_external_provider_number', $accountId)
            ->where($providerMatch)
            ->where('user_type', 2)
            ->first();

        if (!$wallet) {
            // Try user wallet too — accountId could belong to a regular user acting as merchant
            $wallet = Wallet::where('wallet_external_provider_number', $accountId)
                ->where($providerMatch)
                ->first();
        }

        if (!$wallet) {
            Log::warning('HandleMerchantPayIn: no wallet found', ['accountId' => $accountId]);
            return;
        }

        // Deduplicate
        if (Transaction::where('trnx', $txId)->where('remark', 'merchant_payment')->exists()) {
            return;
        }

        DB::beginTransaction();
        try {
            $wallet->balance += $amount;
            $wallet->save();

            Transaction::create([
                'trnx'        => $txId ?? str_rand(),
                'user_id'     => $wallet->user_id,
                'user_type'   => $wallet->user_type,
                'currency_id' => $wallet->currency_id,
                'wallet_id'   => $wallet->id,
                'amount'      => $amount,
                'charge'      => 0,
                'remark'      => 'merchant_payment',
                'type'        => '+',
                'details'     => 'Merchant pay-in from ' . ($data['payerName'] ?? $data['payerAccountId'] ?? 'customer'),
            ]);

            DB::commit();

            Log::info('HandleMerchantPayIn: merchant wallet credited', [
                'wallet_id' => $wallet->id,
                'amount'    => $amount,
                'txId'      => $txId,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('HandleMerchantPayIn: failed', [
                'error'     => $e->getMessage(),
                'accountId' => $accountId,
            ]);
        }
    }
}
