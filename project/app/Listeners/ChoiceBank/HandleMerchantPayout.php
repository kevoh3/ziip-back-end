<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\MerchantPayout;
use App\Models\ProviderTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0022 — Merchant payout result.
 *
 * A merchant-initiated payout has been processed by ChoiceBank.
 * SUCCESS → mark ProviderTransaction complete.
 * FAILED  → refund the merchant wallet.
 */
class HandleMerchantPayout implements ShouldQueue
{
    public function handle(MerchantPayout $event): void
    {
        $data   = $event->data;
        $txId   = $data['txId'] ?? null;
        $status = strtoupper($data['txStatus'] ?? $data['status'] ?? '');

        if (!$txId) {
            Log::warning('HandleMerchantPayout: missing txId', $data);
            return;
        }

        $providerTx = ProviderTransaction::where('provider_tx_id', $txId)
            ->with('transaction')
            ->first();

        if (!$providerTx || $providerTx->completed_at) {
            return;
        }

        $providerTx->markCompleted($status, $data);

        if (in_array($status, ['FAILED', 'REVERSED', 'REJECTED', 'CANCELLED'])) {
            $this->refundMerchant($providerTx, $status, $data);
        }

        Log::info('HandleMerchantPayout: processed', [
            'txId'   => $txId,
            'status' => $status,
        ]);
    }

    private function refundMerchant(ProviderTransaction $providerTx, string $status, array $data): void
    {
        $internalTx = $providerTx->transaction;
        if (!$internalTx) return;

        DB::beginTransaction();
        try {
            $wallet = Wallet::find($internalTx->wallet_id);
            if ($wallet) {
                $wallet->balance += ($internalTx->amount + $internalTx->charge);
                $wallet->save();
            }

            Transaction::create([
                'trnx'        => $internalTx->trnx,
                'user_id'     => $internalTx->user_id,
                'user_type'   => $internalTx->user_type,
                'currency_id' => $internalTx->currency_id,
                'wallet_id'   => $internalTx->wallet_id,
                'amount'      => $internalTx->amount + $internalTx->charge,
                'charge'      => 0,
                'remark'      => 'withdraw_reject',
                'type'        => '+',
                'details'     => 'Merchant payout reversed — ' . $status,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('HandleMerchantPayout: refund failed', ['error' => $e->getMessage()]);
        }
    }
}
