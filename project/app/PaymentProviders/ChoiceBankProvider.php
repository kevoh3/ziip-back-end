<?php

namespace App\PaymentProviders;

use App\Models\PaymentProvider;
use App\Services\ChoiceBankService;

/**
 * ChoiceBank BaaS driver implementing ProviderContract.
 *
 * This is the ONLY class that knows we are using ChoiceBank.
 * Everything above this layer uses ProviderContract + ProviderResponse.
 *
 * Note on OTP: ChoiceBank requires OTP for all outbound transfers.
 * Methods that initiate a transfer return ProviderResponse::pendingOtp()
 * with the txId. The caller must then call confirmWithOtp() when the
 * user submits their OTP code.
 */
class ChoiceBankProvider implements ProviderContract
{
    private ChoiceBankService $api;

    public function __construct(private readonly PaymentProvider $provider)
    {
        // Config is stored encrypted in payment_providers.config
        // If empty, fall back to the global .env config
        $cfg = $provider->config ?? [];

        if (!empty($cfg['base_url'])) {
            $this->api = new ChoiceBankService(
                rtrim($cfg['base_url'], '/'),
                $cfg['sender'],
                $cfg['private_key'],
                $cfg['locale'] ?? 'en_KE',
                (int) ($cfg['timeout'] ?? 30),
            );
        } else {
            $this->api = ChoiceBankService::make();
        }
    }

    public function capabilities(): array
    {
        return [
            'send_mpesa',
            'receive_mpesa',
            'send_airtel',
            'receive_airtel',
            'send_pesalink',
            'send_bank',
            'internal_transfer',
            'airtime',
            'bill_payment',
            'fx_exchange',
            'bulk_transfer',
            'pay_merchant',
        ];
    }

    // -------------------------------------------------------------------------

    public function sendToMobile(array $params): ProviderResponse
    {
        // Map 'network' to a bank code ChoiceBank understands
        $bankCode = $this->networkToBankCode($params['network'] ?? 'mpesa');

        $res = $this->api->applyForTransfer([
            'payerAccountId'              => $params['account_id'],
            'payeeBankCode'               => $bankCode,
            'payeeAccountId'              => $params['phone'],
            'currency'                    => $params['currency'] ?? 'KES',
            'amount'                      => $params['amount'],
            'remark'                      => substr($params['remark'] ?? 'Transfer', 0, 100),
            'payeeMobileForNotification'  => $params['phone'],
        ]);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Transfer failed', $res);
        }

        $txId = $res['data']['txId'] ?? null;

        // ChoiceBank requires OTP after applyForTransfer
        if ($txId) {
            $this->api->sendOtp($txId);
            return ProviderResponse::pendingOtp($txId, $res['data'] ?? []);
        }

        return ProviderResponse::success($res['data'] ?? [], $txId, 'PROCESSING');
    }

    public function sendToBank(array $params): ProviderResponse
    {
        $res = $this->api->applyForTransfer([
            'payerAccountId'  => $params['account_id'],
            'payeeBankCode'   => $params['bank_code'],
            'payeeAccountId'  => $params['bank_account'],
            'payeeAccountName' => $params['account_name'] ?? '',
            'currency'        => $params['currency'] ?? 'KES',
            'amount'          => $params['amount'],
            'remark'          => substr($params['remark'] ?? 'Bank transfer', 0, 100),
        ]);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Transfer failed', $res);
        }

        $txId = $res['data']['txId'] ?? null;

        if ($txId) {
            $this->api->sendOtp($txId);
            return ProviderResponse::pendingOtp($txId, $res['data'] ?? []);
        }

        return ProviderResponse::success($res['data'] ?? [], $txId, 'PROCESSING');
    }

    public function internalTransfer(array $params): ProviderResponse
    {
        $res = $this->api->applyForTransfer([
            'payerAccountId' => $params['from_account_id'],
            'payeeAccountId' => $params['to_account_id'],
            'currency'       => $params['currency'] ?? 'KES',
            'amount'         => $params['amount'],
            'remark'         => substr($params['remark'] ?? 'Internal transfer', 0, 100),
        ]);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Transfer failed', $res);
        }

        $txId = $res['data']['txId'] ?? null;

        if ($txId) {
            $this->api->sendOtp($txId);
            return ProviderResponse::pendingOtp($txId, $res['data'] ?? []);
        }

        return ProviderResponse::success($res['data'] ?? [], $txId, 'PROCESSING');
    }

    public function buyAirtime(array $params): ProviderResponse
    {
        $res = $this->api->airtimePayment(
            $params['account_id'],
            $params['phone'],
            strtoupper($params['operator'] ?? 'SAFARICOM'),
            (int) $params['amount'],
        );

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Airtime purchase failed', $res);
        }

        return ProviderResponse::success(
            $res['data'] ?? [],
            $res['data']['paymentId'] ?? null,
            'PROCESSING'
        );
    }

    public function queryBill(array $params): ProviderResponse
    {
        $res = $this->api->billQuery($params['biller_code'], $params['account_number']);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Bill query failed', $res);
        }

        return ProviderResponse::success($res['data'] ?? []);
    }

    public function payBill(array $params): ProviderResponse
    {
        $res = $this->api->billPayment([
            'payerAccountId' => $params['account_id'],
            'billerCode'     => $params['biller_code'],
            'accountNumber'  => $params['account_number'],
            'amount'         => $params['amount'],
            'reference'      => $params['reference'] ?? '',
        ]);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Bill payment failed', $res);
        }

        return ProviderResponse::success(
            $res['data'] ?? [],
            $res['data']['paymentId'] ?? null,
            'PROCESSING'
        );
    }

    public function payMerchant(array $params): ProviderResponse
    {
        $res = $this->api->applyForMpesaBusinessTransfer(
            $params['account_id'],
            $params['short_code'],
            (int) ($params['pay_type'] ?? 0),
            (int) $params['amount'],
            $params['description'] ?? '',
            $params['account_reference'] ?? '',
        );

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Merchant payment failed', $res);
        }

        $txId = $res['data']['txId'] ?? null;

        if ($txId) {
            $this->api->sendOtp($txId);
            return ProviderResponse::pendingOtp($txId, $res['data'] ?? []);
        }

        return ProviderResponse::success($res['data'] ?? [], $txId, 'PROCESSING');
    }

    public function getFxRate(array $params): ProviderResponse
    {
        $res = $this->api->getForeignExchangeRate(
            strtoupper($params['currency']),
            strtolower($params['operation'] ?? 'buy'),
        );

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'FX rate fetch failed', $res);
        }

        return ProviderResponse::success($res['data'] ?? []);
    }

    public function exchangeCurrency(array $params): ProviderResponse
    {
        $res = $this->api->applyForFcExchange(
            $params['kes_account_id'],
            $params['fc_account_id'],
            strtoupper($params['currency']),
            (float) $params['amount'],
            strtolower($params['operation']),
        );

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'FX exchange failed', $res);
        }

        return ProviderResponse::success(
            $res['data'] ?? [],
            $res['data']['applicationId'] ?? null,
            'PROCESSING'
        );
    }

    public function getBalance(string $accountId): ProviderResponse
    {
        $res = $this->api->getAccountDetails($accountId);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Balance fetch failed', $res);
        }

        return ProviderResponse::success($res['data'] ?? []);
    }

    public function confirmWithOtp(array $params): ProviderResponse
    {
        $res = $this->api->confirmOperation($params['tx_id'], $params['otp']);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'OTP confirmation failed', $res);
        }

        return ProviderResponse::success($res['data'] ?? [], $params['tx_id'], 'PROCESSING');
    }

    public function getTransactionStatus(string $providerTxId): ProviderResponse
    {
        $res = $this->api->getTransResult($providerTxId);

        if (!ChoiceBankService::isSuccess($res)) {
            return ProviderResponse::failure($res['msg'] ?? 'Status fetch failed', $res);
        }

        return ProviderResponse::success(
            $res['data'] ?? [],
            $providerTxId,
            $res['data']['txStatus'] ?? null,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Map a friendly network name to the ChoiceBank bank code.
     * These codes must be confirmed with your ChoiceBank account manager.
     */
    private function networkToBankCode(string $network): string
    {
        return match (strtolower($network)) {
            'mpesa', 'm-pesa', 'safaricom' => 'MPESA',
            'airtel', 'airtel_money'        => 'AIRTEL',
            'telkom', 'tkash'               => 'TELKOM',
            default                         => strtoupper($network),
        };
    }
}
