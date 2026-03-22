<?php

namespace App\Http\Controllers\Api\User;

use App\Models\Agent;
use App\Models\Addon;
use App\Models\Currency;
use App\Models\ProviderTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\Withdrawals;
use App\Services\ProviderRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\ApiController;

class WithdrawalController extends ApiController
{
    public function withdrawForm()
    {
        $success['wallets'] = Wallet::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('balance', '>', 0)
            ->with(['currency'])
            ->get();

        return $this->sendResponse($success, __('success'));
    }

    public function methods(Request $request)
    {
        $success['methods'] = Withdraw::where('currency_id', $request->currency)
            ->where('status', 1)
            ->get();

        if ($success['methods']->isEmpty()) {
            return $this->sendError('Error', ['No withdraw methods found']);
        }

        return $this->sendResponse($success, __('Withdraw methods'));
    }

    public function withdrawSubmit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount'    => 'required|numeric|gt:0',
            'wallet_id' => 'required',
            'method_id' => 'required',
            'user_data' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        $wallet = Wallet::where('id', $request->wallet_id)->where('user_type', 1)->first();
        if (!$wallet) {
            return $this->sendError('Error', ['Wallet not found']);
        }

        $method = Withdraw::where('id', $request->method_id)
            ->where('currency_id', $wallet->currency_id)
            ->first();
        if (!$method) {
            return $this->sendError('Error', ['Withdraw method not found']);
        }

        if ($request->amount < $method->min_amount || $request->amount > $method->max_amount) {
            return $this->sendError('Error', ['Please follow the limit']);
        }

        $charge      = chargeCalc($method, $request->amount);
        $finalAmount = numFormat($request->amount + $charge);

        if ($wallet->balance < $finalAmount) {
            return $this->sendError('Error', ['Insufficient balance']);
        }

        DB::beginTransaction();

        try {
            // Deduct wallet balance
            $wallet->balance -= $finalAmount;
            $wallet->save();

            $trx = str_rand();

            // Create internal transaction record
            $trnx              = new Transaction();
            $trnx->trnx        = $trx;
            $trnx->user_id     = auth()->id();
            $trnx->user_type   = 1;
            $trnx->currency_id = $wallet->currency_id;
            $trnx->wallet_id   = $wallet->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = $charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = 'Withdraw via ' . $method->name;
            $trnx->save();

            // Create withdrawal record
            $withdrawal              = new Withdrawals();
            $withdrawal->trx         = $trx;
            $withdrawal->user_id     = auth()->id();
            $withdrawal->method_id   = $method->id;
            $withdrawal->currency_id = $wallet->currency_id;
            $withdrawal->amount      = $request->amount;
            $withdrawal->charge      = $charge;
            $withdrawal->total_amount = $finalAmount;
            $withdrawal->user_data   = $request->user_data;
            $withdrawal->status      = 0; // pending

            // --- Auto-dispatch via ProviderRouter if method has an operation ---
            if ($method->operation) {
                $currency    = $wallet->currency;
                $userData    = is_string($request->user_data)
                    ? json_decode($request->user_data, true)
                    : (array) $request->user_data;

                $providerResponse = ProviderRouter::run(
                    $method->operation,
                    fn($provider) => $this->dispatchToProvider($provider, $method->operation, $userData, $wallet, $request->amount, $trx),
                    $currency->code ?? null,
                    auth()->user()->country_code ?? null,
                );

                if (!$providerResponse->success && !$providerResponse->needsOtp) {
                    // Provider failed — rollback everything
                    DB::rollBack();
                    return $this->sendError('Error', [$providerResponse->error ?? 'Withdrawal failed. Please try again.']);
                }

                // Track the provider transaction
                $providerTx = ProviderTransaction::create([
                    'transaction_id'   => $trnx->id,
                    'provider_id'      => $this->resolveProviderId($method->operation, $currency->code ?? null),
                    'provider_tx_id'   => $providerResponse->txId,
                    'operation'        => $method->operation,
                    'provider_status'  => $providerResponse->status,
                    'request_payload'  => ['user_data' => $userData, 'amount' => $request->amount],
                    'response_payload' => $providerResponse->data,
                ]);

                $withdrawal->provider_tx_id  = $providerResponse->txId;
                $withdrawal->provider_status = $providerResponse->status;
                $withdrawal->provider_id     = $providerTx->provider_id;
                $withdrawal->status          = $providerResponse->needsOtp ? 0 : 2; // 0=awaiting OTP, 2=processing
            }

            $withdrawal->save();

            DB::commit();

            // If OTP is required, tell the client
            if (isset($providerResponse) && $providerResponse->needsOtp) {
                return $this->sendResponse([
                    'needs_otp'      => true,
                    'provider_tx_id' => $providerResponse->txId,
                    'withdrawal_id'  => $withdrawal->id,
                ], __('Please enter the OTP sent to your phone to complete the withdrawal.'));
            }

            return $this->sendResponse(['success'], __('Withdraw request has been submitted successfully.'));

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Error', ['Something went wrong. Please try again.']);
        }
    }

    /**
     * Confirm an OTP-gated withdrawal. Called after the user receives the OTP.
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

        // Find the pending withdrawal
        $withdrawal = Withdrawals::where('provider_tx_id', $request->provider_tx_id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$withdrawal) {
            return $this->sendError('Error', ['Withdrawal not found']);
        }

        if ($withdrawal->status !== 0) {
            return $this->sendError('Error', ['This withdrawal has already been processed']);
        }

        // Get the provider transaction record
        $providerTx = ProviderTransaction::where('provider_tx_id', $request->provider_tx_id)
            ->where('provider_id', $withdrawal->provider_id)
            ->first();

        if (!$providerTx) {
            return $this->sendError('Error', ['Provider transaction not found']);
        }

        // Resolve the driver and confirm OTP
        $provider = \App\Models\PaymentProvider::find($withdrawal->provider_id);
        if (!$provider) {
            return $this->sendError('Error', ['Provider not found']);
        }

        try {
            $driver   = app($provider->driver_class, ['provider' => $provider]);
            $response = $driver->confirmWithOtp([
                'tx_id' => $request->provider_tx_id,
                'otp'   => $request->otp,
            ]);

            if (!$response->success) {
                return $this->sendError('Error', [$response->error ?? 'OTP confirmation failed']);
            }

            // Update records
            $withdrawal->update(['status' => 2, 'provider_status' => 'PROCESSING']);
            $providerTx->markCompleted('PROCESSING', $response->data);

            return $this->sendResponse(['success'], __('Withdrawal confirmed. It is now being processed.'));

        } catch (\Throwable $e) {
            return $this->sendError('Error', ['OTP confirmation failed. Please try again.']);
        }
    }

    public function history()
    {
        $success['withdrawals'] = Withdrawals::with('method:id,name')
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(15);

        return $this->sendResponse($success, __('Withdraw history'));
    }

    public function cashOutForm()
    {
        $addon = Addon::where('code', 'agent')->where('status', 1)->first();
        if (!$addon) {
            return $this->sendError('Error', ['Agent addon not active/installed']);
        }

        $success['wallets'] = Wallet::where('user_id', auth()->id())->where('user_type', 1)->get();
        $success['charge']  = charge('cashout');

        return $this->sendResponse($success, __('success'));
    }

    public function cashOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver'  => 'required|email',
            'wallet_id' => 'required|integer',
            'amount'    => 'required|numeric|gt:0',
        ], [
            'wallet_id.required' => 'Wallet is required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        $agent = Agent::where('email', $request->receiver)->first();
        if (!$agent) return $this->sendError('Error', ['Agent not found']);

        $senderWallet = Wallet::where('id', $request->wallet_id)
            ->where('user_type', 1)
            ->where('user_id', auth()->id())
            ->first();
        if (!$senderWallet) return $this->sendError('Error', ['Your wallet not found']);

        $currency = Currency::find($senderWallet->currency->id);
        if (!$currency) return $this->sendError('Error', ['Currency not found']);

        $charge = charge('cashout');

        if ($charge->daily_limit != 0 && auth()->user()->cashOutDailyLimit() >= $charge->daily_limit) {
            return $this->sendError('error', ['Your daily cashout limit has been reached']);
        }

        if ($charge->monthly_limit != 0 && auth()->user()->cashOutMonthlyLimit() >= $charge->monthly_limit) {
            return $this->sendError('error', ['Your monthly cashout limit has been reached']);
        }

        if (($charge->minimum * $currency->rate) > $request->amount || ($charge->maximum * $currency->rate) < $request->amount) {
            return $this->sendError('error', ['Please follow the limit']);
        }

        $agentWallet = Wallet::where('currency_id', $currency->id)
            ->where('user_type', 3)
            ->where('user_id', $agent->id)
            ->first();

        if (!$agentWallet) {
            $agentWallet = Wallet::create([
                'user_id'     => $agent->id,
                'user_type'   => 3,
                'currency_id' => $currency->id,
                'balance'     => 0,
            ]);
        }

        $finalCharge = amount(chargeCalc($charge, $request->amount, $currency->rate), $currency->type);
        $finalAmount = amount($request->amount + $finalCharge, $currency->type);

        if ($senderWallet->balance < $finalAmount) return $this->sendError('error', ['Insufficient balance.']);

        $senderWallet->balance -= $finalAmount;
        $senderWallet->update();

        $trnx              = new Transaction();
        $trnx->trnx        = str_rand();
        $trnx->user_id     = auth()->id();
        $trnx->user_type   = 1;
        $trnx->currency_id = $currency->id;
        $trnx->wallet_id   = $senderWallet->id;
        $trnx->amount      = $request->amount;
        $trnx->charge      = $finalCharge;
        $trnx->remark      = 'cash_out';
        $trnx->type        = '-';
        $trnx->details     = trans('Cash out to ') . $agent->email;
        $trnx->save();

        $agentWallet->balance += $request->amount;
        $agentWallet->update();

        $receiverTrnx              = new Transaction();
        $receiverTrnx->trnx        = $trnx->trnx;
        $receiverTrnx->user_id     = $agent->id;
        $receiverTrnx->user_type   = 3;
        $receiverTrnx->currency_id = $currency->id;
        $receiverTrnx->amount      = $request->amount;
        $receiverTrnx->charge      = 0;
        $receiverTrnx->remark      = 'cash_out';
        $receiverTrnx->type        = '+';
        $receiverTrnx->details     = trans('Cash out from ') . auth()->user()->email;
        $receiverTrnx->save();

        try {
            @mailSend('cash_out_user', [
                'amount'    => amount($request->amount, $senderWallet->currency->type, 3),
                'curr'      => $senderWallet->currency->code,
                'agent'     => $agent->email,
                'trnx'      => $trnx->trnx,
                'charge'    => amount($finalCharge, $senderWallet->currency->type, 3),
                'date_time' => dateFormat($trnx->created_at),
                'balance'   => amount($senderWallet->balance, $senderWallet->currency->type, 3),
            ], auth()->user());

            @mailSend('cash_out_agent', [
                'amount'    => amount($request->amount, $senderWallet->currency->type, 3),
                'curr'      => $senderWallet->currency->code,
                'user'      => auth()->user()->email,
                'trnx'      => $trnx->trnx,
                'date_time' => dateFormat($trnx->created_at),
                'balance'   => amount($senderWallet->balance, $senderWallet->currency->type, 3),
            ], $agent);
        } catch (\Throwable $th) {
        }

        return $this->sendResponse(['success'], __('Cash out successful'));
    }

    public function checkAgent(Request $request)
    {
        $receiver['data'] = Agent::where('email', $request->receiver)->first();
        if ($receiver['data']) {
            return response($receiver);
        }
        return response(null);
    }

    public function checkReceiver(Request $request)
    {
        $receiver['data'] = Agent::where('email', $request->receiver)->first();
        if ($receiver['data']) {
            return $this->sendResponse(['Agent exists'], 'Valid receiver found.');
        }
        return $this->sendError('Receiver not found', ['Agent doesn\'t exist associated with this email.']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the correct provider call based on the operation type.
     * user_data is the JSON the user submitted (phone, bank_account, etc.)
     */
    private function dispatchToProvider(
        \App\PaymentProviders\ProviderContract $provider,
        string $operation,
        array $userData,
        Wallet $wallet,
        float $amount,
        string $reference
    ): \App\PaymentProviders\ProviderResponse {
        $accountId = $wallet->wallet_external_provider_number;
        $currency  = $wallet->currency->code ?? 'KES';

        return match ($operation) {
            'send_mpesa'  => $provider->sendToMobile([
                'account_id' => $accountId,
                'phone'      => $userData['phone'] ?? $userData['account'] ?? '',
                'amount'     => $amount,
                'currency'   => $currency,
                'network'    => 'mpesa',
                'remark'     => 'Withdrawal via M-Pesa',
                'reference'  => $reference,
            ]),
            'send_airtel' => $provider->sendToMobile([
                'account_id' => $accountId,
                'phone'      => $userData['phone'] ?? $userData['account'] ?? '',
                'amount'     => $amount,
                'currency'   => $currency,
                'network'    => 'airtel',
                'remark'     => 'Withdrawal via Airtel Money',
                'reference'  => $reference,
            ]),
            'send_bank', 'send_pesalink' => $provider->sendToBank([
                'account_id'   => $accountId,
                'bank_code'    => $userData['bank_code'] ?? '',
                'bank_account' => $userData['account_number'] ?? $userData['account'] ?? '',
                'account_name' => $userData['account_name'] ?? '',
                'amount'       => $amount,
                'currency'     => $currency,
                'remark'       => 'Withdrawal to bank',
                'reference'    => $reference,
            ]),
            default => \App\PaymentProviders\ProviderResponse::failure("Unknown operation: {$operation}"),
        };
    }

    private function resolveProviderId(string $operation, ?string $currencyCode): ?int
    {
        $rule = \App\Models\ProviderRoutingRule::where('operation', $operation)
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        return $rule?->provider_id;
    }
}
