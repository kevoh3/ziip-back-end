<?php

namespace App\PaymentProviders;

/**
 * All payment provider drivers must implement this interface.
 *
 * Methods return a ProviderResponse — a simple DTO with:
 *   ->success   bool
 *   ->txId      string|null   provider's transaction ID
 *   ->status    string|null   provider's status string
 *   ->data      array         full provider response
 *   ->error     string|null   error message on failure
 *
 * The client (controller/service) NEVER knows which provider handled
 * the request — it only works with ProviderResponse.
 */
interface ProviderContract
{
    /**
     * The operation capability keys this driver can handle.
     * Must match entries in the payment_providers.capabilities column.
     *
     * @return string[]
     */
    public function capabilities(): array;

    /**
     * Send money to a mobile money number (M-Pesa, Airtel, etc.)
     *
     * @param array{
     *   account_id:    string,   Provider account to debit
     *   phone:         string,   Recipient phone number (with country code)
     *   amount:        float,
     *   currency:      string,
     *   network:       string,   'mpesa'|'airtel'|'telkom'
     *   remark:        string,
     *   reference:     string,   Our internal transaction reference
     * } $params
     */
    public function sendToMobile(array $params): ProviderResponse;

    /**
     * Send money to a bank account via RTGS/Pesalink/SWIFT.
     *
     * @param array{
     *   account_id:    string,
     *   bank_code:     string,
     *   bank_account:  string,
     *   account_name:  string,
     *   amount:        float,
     *   currency:      string,
     *   remark:        string,
     *   reference:     string,
     * } $params
     */
    public function sendToBank(array $params): ProviderResponse;

    /**
     * Internal wallet-to-wallet transfer within the same provider.
     *
     * @param array{
     *   from_account_id: string,
     *   to_account_id:   string,
     *   amount:          float,
     *   currency:        string,
     *   remark:          string,
     *   reference:       string,
     * } $params
     */
    public function internalTransfer(array $params): ProviderResponse;

    /**
     * Purchase airtime for a phone number.
     *
     * @param array{
     *   account_id: string,
     *   phone:      string,
     *   operator:   string,   'SAFARICOM'|'AIRTEL'|'TELKOM'
     *   amount:     int,
     * } $params
     */
    public function buyAirtime(array $params): ProviderResponse;

    /**
     * Query a utility bill amount before paying.
     *
     * @param array{
     *   biller_code:    string,
     *   account_number: string,
     * } $params
     */
    public function queryBill(array $params): ProviderResponse;

    /**
     * Pay a utility bill.
     *
     * @param array{
     *   account_id:     string,
     *   biller_code:    string,
     *   account_number: string,
     *   amount:         float,
     *   reference:      string,
     * } $params
     */
    public function payBill(array $params): ProviderResponse;

    /**
     * Pay to a merchant Paybill or Till number.
     *
     * @param array{
     *   account_id:       string,
     *   short_code:       string,
     *   pay_type:         int,    0=Paybill, 1=Till
     *   amount:           int,
     *   account_reference: string, (for Paybill)
     *   description:      string,
     * } $params
     */
    public function payMerchant(array $params): ProviderResponse;

    /**
     * Get current FX rate.
     *
     * @param array{
     *   currency:  string,   'USD'|'GBP'|'EUR'|'TZS'|'UGX'|'RWF'
     *   operation: string,   'buy'|'sell'
     * } $params
     */
    public function getFxRate(array $params): ProviderResponse;

    /**
     * Execute a foreign currency exchange.
     *
     * @param array{
     *   kes_account_id: string,
     *   fc_account_id:  string,
     *   currency:       string,
     *   amount:         float,
     *   operation:      string,  'buy'|'sell'
     * } $params
     */
    public function exchangeCurrency(array $params): ProviderResponse;

    /**
     * Get live account balance from the provider.
     */
    public function getBalance(string $accountId): ProviderResponse;

    /**
     * Confirm a pending OTP-gated operation.
     *
     * @param array{
     *   tx_id: string,   The provider's pending transaction ID
     *   otp:   string,
     * } $params
     */
    public function confirmWithOtp(array $params): ProviderResponse;

    /**
     * Get the status of a previously initiated transaction.
     */
    public function getTransactionStatus(string $providerTxId): ProviderResponse;
}
