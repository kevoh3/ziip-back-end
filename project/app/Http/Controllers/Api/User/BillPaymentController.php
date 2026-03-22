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
 * Utility bill payments — provider agnostic.
 *
 * Flow:
 *   1. Client calls POST /user/bill-query to get the amount due for a bill.
 *   2. Client confirms, calls POST /user/pay-bill to pay.
 *
 * Supported billers depend on the underlying provider (e.g. ChoiceBank
 * supports DSTV, GOTV, Startimes, water utilities).
 * The client sees only biller_code + account_number — never the provider.
 */
class BillPaymentController extends ApiController
{
    // Common billers — shown to the client for UX only, not used for routing
    private const BILLERS = [
        ['code' => 'DSTV',      'name' => 'DStv',           'category' => 'tv'],
        ['code' => 'GOTV',      'name' => 'GOtv',           'category' => 'tv'],
        ['code' => 'STARTIMES', 'name' => 'Startimes',      'category' => 'tv'],
        ['code' => 'KPLC_PRE',  'name' => 'KPLC Prepaid',   'category' => 'electricity'],
        ['code' => 'KPLC_POST', 'name' => 'KPLC Postpaid',  'category' => 'electricity'],
        ['code' => 'NAIROBI_WA','name' => 'Nairobi Water',  'category' => 'water'],
    ];

    /**
     * GET /user/pay-bill
     * Returns wallets and biller list for the form.
     */
    public function form()
    {
        $success['wallets'] = Wallet::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('balance', '>', 0)
            ->with('currency')
            ->get();

        $success['billers'] = self::BILLERS;
        $success['charge']  = $this->getCharge();

        return $this->sendResponse($success, 'success');
    }

    /**
     * POST /user/bill-query
     * Query a bill amount before paying. Returns amount_due from provider.
     *
     * Request body:
     *   biller_code     string   e.g. 'DSTV'
     *   account_number  string   customer account/smart card number
     */
    public function billQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'biller_code'    => 'required|string',
            'account_number' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        $response = ProviderRouter::run(
            'bill_payment',
            fn(ProviderContract $p) => $p->queryBill([
                'biller_code'    => strtoupper($request->biller_code),
                'account_number' => $request->account_number,
            ]),
        );

        if (!$response->success) {
            return $this->sendError('Error', [$response->error ?? 'Could not retrieve bill details.']);
        }

        return $this->sendResponse($response->data, 'Bill details retrieved.');
    }

    /**
     * POST /user/pay-bill
     * Pay a utility bill.
     *
     * Request body:
     *   wallet_id       int
     *   biller_code     string
     *   account_number  string
     *   amount          numeric   (from the bill-query result or user-entered for prepaid)
     */
    public function payBill(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_id'      => 'required|integer',
            'biller_code'    => 'required|string',
            'account_number' => 'required|string',
            'amount'         => 'required|numeric|gt:0',
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

        $billerName = collect(self::BILLERS)->firstWhere('code', strtoupper($request->biller_code))['name']
            ?? strtoupper($request->biller_code);

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
            $trnx->remark      = 'bill_payment';
            $trnx->type        = '-';
            $trnx->details     = 'Bill payment — ' . $billerName . ' (' . $request->account_number . ')';
            $trnx->save();

            $response = ProviderRouter::run(
                'bill_payment',
                fn(ProviderContract $p) => $p->payBill([
                    'account_id'     => $wallet->wallet_external_provider_number,
                    'biller_code'    => strtoupper($request->biller_code),
                    'account_number' => $request->account_number,
                    'amount'         => $request->amount,
                    'reference'      => $trx,
                ]),
                $currency->code,
                auth()->user()->country_code ?? null,
            );

            if (!$response->success) {
                DB::rollBack();
                return $this->sendError('Error', [$response->error ?? 'Bill payment failed. Please try again.']);
            }

            ProviderTransaction::create([
                'transaction_id'   => $trnx->id,
                'provider_id'      => $this->resolveProviderId('bill_payment'),
                'provider_tx_id'   => $response->txId,
                'operation'        => 'bill_payment',
                'provider_status'  => $response->status ?? 'PROCESSING',
                'request_payload'  => $request->only(['biller_code', 'account_number', 'amount']),
                'response_payload' => $response->data,
            ]);

            DB::commit();

            return $this->sendResponse(
                ['trnx' => $trx, 'biller' => $billerName],
                __('Bill payment successful.')
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Error', ['Something went wrong. Please try again.']);
        }
    }

    /**
     * GET /user/bill-history
     */
    public function history()
    {
        $success['history'] = Transaction::where('user_id', auth()->id())
            ->where('user_type', 1)
            ->where('remark', 'bill_payment')
            ->with('currency')
            ->latest()
            ->paginate(15);

        return $this->sendResponse($success, 'Bill payment history');
    }

    // -------------------------------------------------------------------------

    private function getCharge()
    {
        try {
            return charge('bill-payment');
        } catch (\Throwable $e) {
            return (object) [
                'percent_charge' => 0,
                'fixed_charge'   => 0,
                'minimum'        => 1,
                'maximum'        => 100000,
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
