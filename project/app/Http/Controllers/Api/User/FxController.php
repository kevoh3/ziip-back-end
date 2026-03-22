<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\ApiController;
use App\Models\Currency;
use App\Models\ProviderTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use App\PaymentProviders\ProviderContract;
use App\Services\ProviderRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Foreign currency exchange — provider agnostic.
 *
 * The client sees: KES account, FC account, currency, amount, direction.
 * Which provider executes the FX (ChoiceBank, etc.) is invisible.
 *
 * Flow:
 *   1. GET  /user/fx-rate?currency=USD&operation=buy  — get live rate
 *   2. GET  /user/fx-exchange                          — form data (wallets)
 *   3. POST /user/fx-exchange                          — execute exchange
 */
class FxController extends ApiController
{
    // Supported foreign currencies
    private const FC_CURRENCIES = ['USD', 'GBP', 'EUR', 'CNY', 'TZS', 'UGX', 'RWF'];

    /**
     * GET /user/fx-rate
     * Returns the live rate for a given currency and direction.
     *
     * Query params:
     *   currency   string   USD | GBP | EUR | CNY | TZS | UGX | RWF
     *   operation  string   buy | sell  (default: buy)
     */
    public function getRate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currency'  => 'required|in:' . implode(',', self::FC_CURRENCIES),
            'operation' => 'nullable|in:buy,sell',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        $response = ProviderRouter::run(
            'fx_exchange',
            fn(ProviderContract $p) => $p->getFxRate([
                'currency'  => strtoupper($request->currency),
                'operation' => strtolower($request->operation ?? 'buy'),
            ]),
            'KES',
            auth()->user()->country_code ?? null,
        );

        if (!$response->success) {
            return $this->sendError('Error', [$response->error ?? 'Could not retrieve FX rate.']);
        }

        return $this->sendResponse($response->data, 'FX rate retrieved.');
    }

    /**
     * GET /user/fx-exchange
     * Returns the user's wallets grouped by currency for the exchange form.
     */
    public function form()
    {
        $wallets = Wallet::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->with('currency')
            ->get();

        $success['kes_wallets'] = $wallets->filter(
            fn($w) => strtoupper($w->currency->code ?? '') === 'KES'
        )->values();

        $success['fc_wallets'] = $wallets->filter(
            fn($w) => in_array(strtoupper($w->currency->code ?? ''), self::FC_CURRENCIES)
        )->values();

        $success['supported_currencies'] = self::FC_CURRENCIES;

        return $this->sendResponse($success, 'success');
    }

    /**
     * POST /user/fx-exchange
     * Execute a foreign currency exchange.
     *
     * Request body:
     *   kes_wallet_id  int      The KES wallet to use
     *   fc_wallet_id   int      The foreign currency wallet
     *   currency       string   USD | GBP | EUR | ...
     *   amount         numeric  Amount to exchange
     *   operation      string   'buy'  = spend KES, receive FC
     *                           'sell' = spend FC, receive KES
     */
    public function exchange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kes_wallet_id' => 'required|integer',
            'fc_wallet_id'  => 'required|integer',
            'currency'      => 'required|in:' . implode(',', self::FC_CURRENCIES),
            'amount'        => 'required|numeric|gt:0',
            'operation'     => 'required|in:buy,sell',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        // Load wallets
        $kesWallet = Wallet::where('id', $request->kes_wallet_id)
            ->where('user_type', 1)
            ->where('user_id', auth()->id())
            ->with('currency')
            ->first();
        if (!$kesWallet) return $this->sendError('Error', ['KES wallet not found']);

        $fcWallet = Wallet::where('id', $request->fc_wallet_id)
            ->where('user_type', 1)
            ->where('user_id', auth()->id())
            ->with('currency')
            ->first();
        if (!$fcWallet) return $this->sendError('Error', ['Foreign currency wallet not found']);

        // Validate the debit side has sufficient balance
        // 'buy' = debit KES wallet,  'sell' = debit FC wallet
        $debitWallet = $request->operation === 'buy' ? $kesWallet : $fcWallet;

        if ($debitWallet->balance < $request->amount) {
            return $this->sendError('Error', ['Insufficient balance in source wallet']);
        }

        DB::beginTransaction();
        try {
            // Optimistically debit the source wallet
            $debitWallet->balance -= $request->amount;
            $debitWallet->save();

            $trx               = str_rand();
            $trnx              = new Transaction();
            $trnx->trnx        = $trx;
            $trnx->user_id     = auth()->id();
            $trnx->user_type   = 1;
            $trnx->currency_id = $debitWallet->currency_id;
            $trnx->wallet_id   = $debitWallet->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'money_exchange';
            $trnx->type        = '-';
            $trnx->details     = 'FX ' . strtoupper($request->operation)
                . ' ' . strtoupper($request->currency)
                . ' amount ' . $request->amount;
            $trnx->save();

            $response = ProviderRouter::run(
                'fx_exchange',
                fn(ProviderContract $p) => $p->exchangeCurrency([
                    'kes_account_id' => $kesWallet->wallet_external_provider_number,
                    'fc_account_id'  => $fcWallet->wallet_external_provider_number,
                    'currency'       => strtoupper($request->currency),
                    'amount'         => $request->amount,
                    'operation'      => strtolower($request->operation),
                ]),
                $debitWallet->currency->code ?? 'KES',
                auth()->user()->country_code ?? null,
            );

            if (!$response->success) {
                DB::rollBack();
                return $this->sendError('Error', [$response->error ?? 'Currency exchange failed. Please try again.']);
            }

            ProviderTransaction::create([
                'transaction_id'   => $trnx->id,
                'provider_id'      => $this->resolveProviderId('fx_exchange'),
                'provider_tx_id'   => $response->txId,
                'operation'        => 'fx_exchange',
                'provider_status'  => $response->status ?? 'PROCESSING',
                'request_payload'  => $request->only(['currency', 'amount', 'operation']),
                'response_payload' => $response->data,
            ]);

            // Credit side is handled asynchronously via the ChoiceBank callback (event 0013)
            // The FxResult event listener will update the credit wallet once the FX is confirmed.
            // See: App\Events\ChoiceBank\FxResult

            DB::commit();

            return $this->sendResponse(
                [
                    'trnx'        => $trx,
                    'provider_tx' => $response->txId,
                    'status'      => 'PROCESSING',
                ],
                __('Exchange initiated. Your ' . strtoupper($request->currency) . ' wallet will be credited once confirmed.')
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Error', ['Something went wrong. Please try again.']);
        }
    }

    /**
     * GET /user/fx-history
     */
    public function history()
    {
        $success['history'] = Transaction::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('remark', 'money_exchange')
            ->with('currency')
            ->latest()
            ->paginate(15);

        return $this->sendResponse($success, 'FX exchange history');
    }

    // -------------------------------------------------------------------------

    private function resolveProviderId(string $operation): ?int
    {
        return \App\Models\ProviderRoutingRule::where('operation', $operation)
            ->where('is_active', true)
            ->orderBy('priority')
            ->value('provider_id');
    }
}
