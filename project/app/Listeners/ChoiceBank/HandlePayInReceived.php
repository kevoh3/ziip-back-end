<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\PayInReceived;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0003 — Inbound transfer (pay-in) received.
 *
 * Someone sent money to a user's ChoiceBank account from outside.
 * We find the wallet by the ChoiceBank accountId, credit it,
 * and create a Transaction + Deposit record.
 */
class HandlePayInReceived implements ShouldQueue
{
    public function handle(PayInReceived $event): void
    {
        $data      = $event->data;
        $accountId = $data['accountId'] ?? $data['payeeAccountId'] ?? null;
        $txId      = $data['txId'] ?? null;
        $amount    = (float) ($data['amount'] ?? 0);
        $currency  = strtoupper($data['currency'] ?? 'KES');

        if (!$accountId || $amount <= 0) {
            Log::warning('HandlePayInReceived: invalid payload', $data);
            return;
        }

        // Find the wallet linked to this ChoiceBank account.
        // wallet_external_provider stores the PaymentProvider name ('ChoiceBank BaaS')
        // OR the slug ('choicebank') as fallback — match both.
        $wallet = Wallet::where('wallet_external_provider_number', $accountId)
            ->where(function ($q) {
                $q->where('wallet_external_provider', 'ChoiceBank BaaS')
                  ->orWhere('wallet_external_provider', 'choicebank');
            })
            ->first();

        if (!$wallet) {
            Log::warning('HandlePayInReceived: no wallet found for accountId', [
                'accountId' => $accountId,
                'txId'      => $txId,
            ]);
            return;
        }

        // Guard against duplicate callbacks using provider_tx_id
        $alreadyProcessed = Transaction::where('trnx', $txId)
            ->where('remark', 'deposit')
            ->exists();

        if ($alreadyProcessed) {
            Log::info('HandlePayInReceived: duplicate callback skipped', ['txId' => $txId]);
            return;
        }

        $currencyModel = Currency::find($wallet->currency_id);

        DB::beginTransaction();
        try {
            $wallet->balance += $amount;
            $wallet->save();

            $trnx              = Transaction::create([
                'trnx'        => $txId ?? str_rand(),
                'user_id'     => $wallet->user_id,
                'user_type'   => $wallet->user_type,
                'currency_id' => $wallet->currency_id,
                'wallet_id'   => $wallet->id,
                'amount'      => $amount,
                'charge'      => 0,
                'remark'      => 'deposit',
                'type'        => '+',
                'details'     => 'Inbound transfer from ' . ($data['senderName'] ?? $data['senderAccountId'] ?? 'external'),
            ]);

            // Create a Deposit record so it shows in deposit history
            Deposit::create([
                'user_id'     => $wallet->user_id,
                'user_type'   => $wallet->user_type,
                'currency_id' => $wallet->currency_id,
                'wallet_id'   => $wallet->id,
                'amount'      => $amount,
                'charge'      => 0,
                'status'      => 1, // completed
                'trx'         => $txId ?? str_rand(),
                'details'     => 'ChoiceBank pay-in',
                'information' => json_encode($data),
            ]);

            DB::commit();

            Log::info('HandlePayInReceived: wallet credited', [
                'user_id'   => $wallet->user_id,
                'amount'    => $amount,
                'currency'  => $currency,
                'txId'      => $txId,
            ]);

            // Notify user
            try {
                $user = $wallet->user ?? \App\Models\User::find($wallet->user_id);
                if ($user) {
                    @mailSend('deposit', [
                        'amount'   => $amount,
                        'curr'     => $currencyModel?->code ?? $currency,
                        'trnx'     => $trnx->trnx,
                        'date_time'=> now()->toDateTimeString(),
                        'balance'  => $wallet->balance,
                    ], $user);
                }
            } catch (\Throwable $e) {
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('HandlePayInReceived: failed to credit wallet', [
                'error'     => $e->getMessage(),
                'accountId' => $accountId,
                'txId'      => $txId,
            ]);
        }
    }
}
