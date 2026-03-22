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
 * Airtime top-up — provider agnostic.
 *
 * Client sends: phone, operator (safaricom/airtel/telkom), amount.
 * Which backend processes it (ChoiceBank, direct Safaricom, etc.)
 * is invisible to the client.
 */
class AirtimeController extends ApiController
{
    /**
     * GET /user/buy-airtime
     * Returns wallets and available operators for the form.
     */
    public function form()
    {
        $success['wallets'] = Wallet::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('balance', '>', 0)
            ->with('currency')
            ->get();

        // Operators shown to the user — routing is server-side
        $success['operators'] = [
            ['key' => 'SAFARICOM', 'name' => 'Safaricom (M-Pesa)'],
            ['key' => 'AIRTEL',    'name' => 'Airtel Money'],
            ['key' => 'TELKOM',    'name' => 'Telkom (T-Kash)'],
        ];

        $success['charge'] = $this->getCharge();

        return $this->sendResponse($success, 'success');
    }

    /**
     * POST /user/buy-airtime
     *
     * Request body:
     *   wallet_id  int
     *   phone      string   recipient phone (with country code e.g. 254712345678)
     *   operator   string   SAFARICOM | AIRTEL | TELKOM
     *   amount     int      airtime amount in KES (whole number)
     */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_id' => 'required|integer',
            'phone'     => 'required|string|min:9|max:15',
            'operator'  => 'required|in:SAFARICOM,AIRTEL,TELKOM',
            'amount'    => 'required|integer|min:1',
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

        $chargeConfig = $this->getCharge();
        $charge       = chargeCalc($chargeConfig, $request->amount, $currency->rate);
        $finalAmount  = amount($request->amount + $charge, $currency->type);

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
            $trnx->remark      = 'airtime';
            $trnx->type        = '-';
            $trnx->details     = 'Airtime ' . $request->operator . ' → ' . $request->phone;
            $trnx->save();

            $response = ProviderRouter::run(
                'airtime',
                fn(ProviderContract $p) => $p->buyAirtime([
                    'account_id' => $wallet->wallet_external_provider_number,
                    'phone'      => $request->phone,
                    'operator'   => $request->operator,
                    'amount'     => (int) $request->amount,
                ]),
                $currency->code,
                auth()->user()->country_code ?? null,
            );

            if (!$response->success) {
                DB::rollBack();
                return $this->sendError('Error', [$response->error ?? 'Airtime purchase failed. Please try again.']);
            }

            ProviderTransaction::create([
                'transaction_id'   => $trnx->id,
                'provider_id'      => $this->resolveProviderId('airtime'),
                'provider_tx_id'   => $response->txId,
                'operation'        => 'airtime',
                'provider_status'  => $response->status ?? 'PROCESSING',
                'request_payload'  => $request->only(['phone', 'operator', 'amount']),
                'response_payload' => $response->data,
            ]);

            DB::commit();

            return $this->sendResponse(
                ['trnx' => $trx],
                __('Airtime purchase successful.')
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Error', ['Something went wrong. Please try again.']);
        }
    }

    /**
     * GET /user/airtime-history
     */
    public function history()
    {
        $success['history'] = Transaction::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('remark', 'airtime')
            ->with('currency')
            ->latest()
            ->paginate(15);

        return $this->sendResponse($success, 'Airtime history');
    }

    // -------------------------------------------------------------------------

    private function getCharge()
    {
        try {
            return charge('airtime');
        } catch (\Throwable $e) {
            // Fallback if charge slug not yet seeded
            return (object) [
                'percent_charge' => 0,
                'fixed_charge'   => 0,
                'minimum'        => 5,
                'maximum'        => 10000,
                'daily_limit'    => 0,
                'monthly_limit'  => 0,
            ];
        }
    }

    private function resolveProviderId(string $operation): ?int
    {
        return \App\Models\ProviderRoutingRule::where('operation', $operation)
            ->where('is_active', true)
            ->orderBy('priority')
            ->value('provider_id');
    }
}
