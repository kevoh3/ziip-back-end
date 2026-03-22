<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\FxResult;
use App\Models\Currency;
use App\Models\ProviderTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0013 — Foreign exchange result.
 *
 * ChoiceBank has completed the FX exchange.
 * We credit the receiving wallet (FC wallet for 'buy', KES wallet for 'sell')
 * and create the credit-side Transaction record.
 */
class HandleFxResult implements ShouldQueue
{
    public function handle(FxResult $event): void
    {
        $data          = $event->data;
        $applicationId = $data['applicationId'] ?? null;
        $status        = strtoupper($data['status'] ?? '');
        $creditAmount  = (float) ($data['creditAmount'] ?? $data['fcAmount'] ?? $data['kesAmount'] ?? 0);
        $creditAccount = $data['creditAccountId'] ?? null;
        $operation     = strtolower($data['operation'] ?? 'buy');

        if (!$applicationId) {
            Log::warning('HandleFxResult: missing applicationId', $data);
            return;
        }

        // Find the ProviderTransaction that initiated this FX exchange
        $providerTx = ProviderTransaction::where('provider_tx_id', $applicationId)
            ->with('transaction')
            ->first();

        if (!$providerTx) {
            Log::warning('HandleFxResult: no ProviderTransaction found', [
                'applicationId' => $applicationId,
            ]);
            return;
        }

        if ($providerTx->completed_at) {
            return; // Already processed
        }

        $providerTx->markCompleted($status, $data);

        if (!in_array($status, ['SUCCESS', 'COMPLETED', 'APPROVED'])) {
            // FX failed — refund the debit side
            $this->refundFxDebit($providerTx, $status, $data);
            return;
        }

        if ($creditAmount <= 0 || !$creditAccount) {
            Log::warning('HandleFxResult: missing credit info', $data);
            return;
        }

        // Find the receiving wallet by ChoiceBank accountId
        $creditWallet = Wallet::where('wallet_external_provider_number', $creditAccount)
            ->where('wallet_external_provider', 'ChoiceBank')
            ->first();

        if (!$creditWallet) {
            Log::warning('HandleFxResult: credit wallet not found', [
                'creditAccount' => $creditAccount,
            ]);
            return;
        }

        // Deduplicate
        $trnxRef = $providerTx->transaction?->trnx ?? $applicationId;
        if (Transaction::where('trnx', $trnxRef)->where('type', '+')->where('remark', 'money_exchange')->exists()) {
            return;
        }

        DB::beginTransaction();
        try {
            $creditWallet->balance += $creditAmount;
            $creditWallet->save();

            Transaction::create([
                'trnx'        => $trnxRef,
                'user_id'     => $creditWallet->user_id,
                'user_type'   => $creditWallet->user_type,
                'currency_id' => $creditWallet->currency_id,
                'wallet_id'   => $creditWallet->id,
                'amount'      => $creditAmount,
                'charge'      => 0,
                'remark'      => 'money_exchange',
                'type'        => '+',
                'details'     => 'FX exchange ' . strtoupper($operation) . ' credited',
            ]);

            DB::commit();

            Log::info('HandleFxResult: FX credit applied', [
                'applicationId' => $applicationId,
                'creditAmount'  => $creditAmount,
                'wallet_id'     => $creditWallet->id,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('HandleFxResult: failed to credit wallet', [
                'error'         => $e->getMessage(),
                'applicationId' => $applicationId,
            ]);
        }
    }

    private function refundFxDebit(ProviderTransaction $providerTx, string $status, array $data): void
    {
        $internalTx = $providerTx->transaction;
        if (!$internalTx) return;

        DB::beginTransaction();
        try {
            $wallet = Wallet::find($internalTx->wallet_id);
            if ($wallet) {
                $wallet->balance += $internalTx->amount;
                $wallet->save();
            }

            Transaction::create([
                'trnx'        => $internalTx->trnx,
                'user_id'     => $internalTx->user_id,
                'user_type'   => $internalTx->user_type,
                'currency_id' => $internalTx->currency_id,
                'wallet_id'   => $internalTx->wallet_id,
                'amount'      => $internalTx->amount,
                'charge'      => 0,
                'remark'      => 'money_exchange',
                'type'        => '+',
                'details'     => 'FX exchange reversed — ' . $status,
            ]);

            DB::commit();

            Log::info('HandleFxResult: FX debit refunded', [
                'applicationId' => $providerTx->provider_tx_id,
                'amount'        => $internalTx->amount,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('HandleFxResult: refund failed', ['error' => $e->getMessage()]);
        }
    }
}
