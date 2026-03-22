<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\PayoutResult;
use App\Models\ProviderTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdrawals;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0002 — Outbound transfer (payout) result.
 *
 * SUCCESS → mark withdrawal accepted, mark provider_transaction complete.
 * FAILED  → refund the wallet, mark withdrawal rejected, reverse the transaction.
 */
class HandlePayoutResult implements ShouldQueue
{
    public function handle(PayoutResult $event): void
    {
        $data   = $event->data;
        $txId   = $data['txId'] ?? null;
        $status = strtoupper($data['txStatus'] ?? $data['status'] ?? '');

        if (!$txId) {
            Log::warning('HandlePayoutResult: missing txId', $data);
            return;
        }

        // Find the provider transaction record
        $providerTx = ProviderTransaction::where('provider_tx_id', $txId)
            ->with('transaction')
            ->first();

        if (!$providerTx) {
            Log::warning('HandlePayoutResult: no ProviderTransaction found', ['txId' => $txId]);
            return;
        }

        // Already finalized — avoid double processing
        if ($providerTx->completed_at) {
            return;
        }

        $providerTx->markCompleted($status, $data);

        // Locate matching withdrawal record via transaction trnx
        $withdrawal = null;
        if ($providerTx->transaction) {
            $withdrawal = Withdrawals::where('trx', $providerTx->transaction->trnx)->first();
        }

        if ($status === 'SUCCESS' || $status === 'COMPLETED') {
            if ($withdrawal) {
                $withdrawal->update(['status' => 1, 'provider_status' => $status]);
            }

            Log::info('HandlePayoutResult: payout succeeded', ['txId' => $txId]);

            // Notify user
            $this->notifyUser($providerTx, 'success', $data);

        } elseif (in_array($status, ['FAILED', 'REVERSED', 'REJECTED', 'CANCELLED'])) {
            // Refund the wallet
            $this->refundWallet($providerTx, $withdrawal, $status, $data);

            Log::info('HandlePayoutResult: payout failed — wallet refunded', [
                'txId'   => $txId,
                'status' => $status,
            ]);

            $this->notifyUser($providerTx, 'failed', $data);
        }
    }

    private function refundWallet(
        ProviderTransaction $providerTx,
        ?Withdrawals $withdrawal,
        string $status,
        array $data
    ): void {
        $internalTx = $providerTx->transaction;
        if (!$internalTx) return;

        DB::beginTransaction();
        try {
            // Credit back the full amount (amount + charge)
            $refundAmount = $internalTx->amount + $internalTx->charge;

            $wallet = Wallet::find($internalTx->wallet_id);
            if ($wallet) {
                $wallet->balance += $refundAmount;
                $wallet->save();
            }

            // Create a reversal transaction record
            Transaction::create([
                'trnx'        => $internalTx->trnx,
                'user_id'     => $internalTx->user_id,
                'user_type'   => $internalTx->user_type,
                'currency_id' => $internalTx->currency_id,
                'wallet_id'   => $internalTx->wallet_id,
                'amount'      => $refundAmount,
                'charge'      => 0,
                'remark'      => 'withdraw_reject',
                'type'        => '+',
                'details'     => 'Withdrawal reversed — ' . $status,
            ]);

            if ($withdrawal) {
                $withdrawal->update([
                    'status'          => 3, // rejected
                    'provider_status' => $status,
                    'reject_reason'   => $data['failReason'] ?? $data['reason'] ?? $status,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('HandlePayoutResult: refund failed', [
                'error' => $e->getMessage(),
                'trnx'  => $internalTx->trnx,
            ]);
        }
    }

    private function notifyUser(ProviderTransaction $providerTx, string $result, array $data): void
    {
        try {
            $tx   = $providerTx->transaction;
            $user = $tx?->user;
            if (!$user) return;

            $template = $result === 'success' ? 'withdraw_accept' : 'withdraw_reject';

            @mailSend($template, [
                'trnx'   => $tx->trnx,
                'amount' => $tx->amount,
            ], $user);
        } catch (\Throwable $e) {
        }
    }
}
