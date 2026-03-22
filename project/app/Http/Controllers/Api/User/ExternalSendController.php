<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\ApiController;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\ProviderTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use App\PaymentProviders\ProviderContract;
use App\PaymentProviders\ProviderResponse;
use App\Services\ProviderRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Handles sending money to external destinations:
 *   - Mobile money (M-Pesa, Airtel Money)
 *   - Bank accounts (Pesalink / RTGS)
 *
 * The client sees only the DESTINATION (M-Pesa, Airtel, bank name).
 * Which provider handles the actual transfer is invisible to the client
 * and controlled by the provider_routing_rules table.
 */
class ExternalSendController extends ApiController
{
    /**
     * GET /user/send-to-mobile
     * Returns the user's wallets and available networks.
     */
    public function sendToMobileForm()
    {
        $success['wallets'] = Wallet::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('balance', '>', 0)
            ->with('currency')
            ->get();

        // Networks available — purely UI info, routing handled server-side
        $success['networks'] = [
            ['key' => 'mpesa',  'name' => 'M-Pesa',      'operator' => 'SAFARICOM'],
            ['key' => 'airtel', 'name' => 'Airtel Money', 'operator' => 'AIRTEL'],
            ['key' => 'telkom', 'name' => 'T-Kash',       'operator' => 'TELKOM'],
        ];

        $success['charge'] = charge('send-to-mobile');

        return $this->sendResponse($success, 'success');
    }

    /**
     * POST /user/send-to-mobile
     * Send money to a mobile money number (M-Pesa, Airtel, etc.)
     */
    public function sendToMobile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_id' => 'required|integer',
            'phone'     => 'required|string|min:9|max:15',
            'network'   => 'required|in:mpesa,airtel,telkom',
            'amount'    => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        $wallet = Wallet::where('id', $request->wallet_id)
            ->where('user_type', 1)
            ->where('user_id', auth()->id())
            ->first();
        if (!$wallet) return $this->sendError('Error', ['Wallet not found']);

        $currency = Currency::find($wallet->currency_id);
        if (!$currency) return $this->sendError('Error', ['Currency not found']);

        $charge      = chargeCalc(charge('send-to-mobile'), $request->amount, $currency->rate);
        $finalAmount = amount($request->amount + $charge, $currency->type);

        if ($wallet->balance < $finalAmount) {
            return $this->sendError('Error', ['Insufficient balance']);
        }

        // Map network to router operation
        $operation = match ($request->network) {
            'mpesa'  => 'send_mpesa',
            'airtel' => 'send_airtel',
            default  => 'send_mpesa',
        };

        DB::beginTransaction();
        try {
            $wallet->balance -= $finalAmount;
            $wallet->save();

            $trx               = str_rand();
            $trnx              = new Transaction();
            $trnx->trnx        = $trx;
            $trnx->user_id     = auth()->id();
            $trnx->user_type   = 1;
            $trnx->currency_id = $currency->id;
            $trnx->wallet_id   = $wallet->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = $charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = 'Send to ' . strtoupper($request->network) . ' ' . $request->phone;
            $trnx->save();

            $response = ProviderRouter::run(
                $operation,
                fn(ProviderContract $p) => $p->sendToMobile([
                    'account_id' => $wallet->wallet_external_provider_number,
                    'phone'      => $request->phone,
                    'amount'     => $request->amount,
                    'currency'   => $currency->code,
                    'network'    => $request->network,
                    'remark'     => 'Send to mobile',
                    'reference'  => $trx,
                ]),
                $currency->code,
                auth()->user()->country_code ?? null,
            );

            if (!$response->success && !$response->needsOtp) {
                DB::rollBack();
                return $this->sendError('Error', [$response->error ?? 'Transfer failed. Please try again.']);
            }

            ProviderTransaction::create([
                'transaction_id'   => $trnx->id,
                'provider_id'      => $this->resolveProviderId($operation),
                'provider_tx_id'   => $response->txId,
                'operation'        => $operation,
                'provider_status'  => $response->status,
                'request_payload'  => ['phone' => $request->phone, 'amount' => $request->amount, 'network' => $request->network],
                'response_payload' => $response->data,
            ]);

            DB::commit();

            if ($response->needsOtp) {
                return $this->sendResponse([
                    'needs_otp'      => true,
                    'provider_tx_id' => $response->txId,
                    'trnx'           => $trx,
                ], __('Enter the OTP sent to your phone to complete the transfer.'));
            }

            return $this->sendResponse(['trnx' => $trx], __('Transfer initiated successfully.'));

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Error', ['Something went wrong. Please try again.']);
        }
    }

    /**
     * GET /user/send-to-bank
     * Returns wallets for bank transfer form.
     */
    public function sendToBankForm()
    {
        $success['wallets'] = Wallet::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('balance', '>', 0)
            ->with('currency')
            ->get();

        $success['charge'] = charge('send-to-bank');

        return $this->sendResponse($success, 'success');
    }

    /**
     * POST /user/send-to-bank
     * Send money to a bank account via Pesalink / RTGS.
     */
    public function sendToBank(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_id'    => 'required|integer',
            'bank_code'    => 'required|string',
            'bank_account' => 'required|string',
            'account_name' => 'required|string',
            'amount'       => 'required|numeric|gt:0',
            'remark'       => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        $wallet = Wallet::where('id', $request->wallet_id)
            ->where('user_type', 1)
            ->where('user_id', auth()->id())
            ->first();
        if (!$wallet) return $this->sendError('Error', ['Wallet not found']);

        $currency = Currency::find($wallet->currency_id);
        if (!$currency) return $this->sendError('Error', ['Currency not found']);

        $charge      = chargeCalc(charge('send-to-bank'), $request->amount, $currency->rate);
        $finalAmount = amount($request->amount + $charge, $currency->type);

        if ($wallet->balance < $finalAmount) {
            return $this->sendError('Error', ['Insufficient balance']);
        }

        DB::beginTransaction();
        try {
            $wallet->balance -= $finalAmount;
            $wallet->save();

            $trx               = str_rand();
            $trnx              = new Transaction();
            $trnx->trnx        = $trx;
            $trnx->user_id     = auth()->id();
            $trnx->user_type   = 1;
            $trnx->currency_id = $currency->id;
            $trnx->wallet_id   = $wallet->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = $charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = 'Bank transfer to ' . $request->account_name;
            $trnx->save();

            $response = ProviderRouter::run(
                'send_bank',
                fn(ProviderContract $p) => $p->sendToBank([
                    'account_id'   => $wallet->wallet_external_provider_number,
                    'bank_code'    => $request->bank_code,
                    'bank_account' => $request->bank_account,
                    'account_name' => $request->account_name,
                    'amount'       => $request->amount,
                    'currency'     => $currency->code,
                    'remark'       => $request->remark ?? 'Bank transfer',
                    'reference'    => $trx,
                ]),
                $currency->code,
                auth()->user()->country_code ?? null,
            );

            if (!$response->success && !$response->needsOtp) {
                DB::rollBack();
                return $this->sendError('Error', [$response->error ?? 'Transfer failed. Please try again.']);
            }

            ProviderTransaction::create([
                'transaction_id'   => $trnx->id,
                'provider_id'      => $this->resolveProviderId('send_bank'),
                'provider_tx_id'   => $response->txId,
                'operation'        => 'send_bank',
                'provider_status'  => $response->status,
                'request_payload'  => $request->only(['bank_code', 'bank_account', 'account_name', 'amount']),
                'response_payload' => $response->data,
            ]);

            DB::commit();

            if ($response->needsOtp) {
                return $this->sendResponse([
                    'needs_otp'      => true,
                    'provider_tx_id' => $response->txId,
                    'trnx'           => $trx,
                ], __('Enter the OTP sent to your phone to complete the bank transfer.'));
            }

            return $this->sendResponse(['trnx' => $trx], __('Bank transfer initiated successfully.'));

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Error', ['Something went wrong. Please try again.']);
        }
    }

    /**
     * POST /user/confirm-send-otp
     * Confirm OTP for any pending external send (mobile or bank).
     */
    public function confirmOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'provider_tx_id' => 'required|string',
            'otp'            => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        // Find the pending provider transaction belonging to this user
        $providerTx = ProviderTransaction::where('provider_tx_id', $request->provider_tx_id)
            ->whereHas('transaction', fn($q) => $q->where('user_id', auth()->id()))
            ->with('provider')
            ->first();

        if (!$providerTx) {
            return $this->sendError('Error', ['Transaction not found']);
        }

        if ($providerTx->completed_at) {
            return $this->sendError('Error', ['This transaction has already been processed']);
        }

        if (!$providerTx->provider) {
            return $this->sendError('Error', ['Provider not found']);
        }

        try {
            $driver   = app($providerTx->provider->driver_class, ['provider' => $providerTx->provider]);
            $response = $driver->confirmWithOtp([
                'tx_id' => $request->provider_tx_id,
                'otp'   => $request->otp,
            ]);

            if (!$response->success) {
                return $this->sendError('Error', [$response->error ?? 'Invalid OTP. Please try again.']);
            }

            $providerTx->markCompleted('PROCESSING', $response->data);

            return $this->sendResponse(
                ['trnx' => $providerTx->transaction->trnx ?? null],
                __('OTP confirmed. Your transfer is now being processed.')
            );

        } catch (\Throwable $e) {
            return $this->sendError('Error', ['OTP confirmation failed. Please try again.']);
        }
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
