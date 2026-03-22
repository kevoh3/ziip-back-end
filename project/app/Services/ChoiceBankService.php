<?php

namespace App\Services;

use App\Support\ChoiceSigner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ChoiceBank BaaS HTTP client.
 *
 * Every public method maps 1-to-1 to a ChoiceBank API endpoint.
 * All requests are signed (SHA-256) via ChoiceSigner.
 * All responses are returned as plain arrays.
 *
 * Usage:
 *   $choice = ChoiceBankService::make();
 *   $result = $choice->getAccountDetails('ACC123');
 */
class ChoiceBankService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sender,
        private readonly string $privateKey,
        private readonly string $locale,
        private readonly int    $timeout,
    ) {}

    public static function make(): self
    {
        $cfg = config('services.choice');

        return new self(
            rtrim($cfg['base_url'], '/'),
            $cfg['sender'],
            $cfg['private_key'],
            $cfg['locale'],
            (int) $cfg['timeout'],
        );
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }

    private function envelope(array $params): array
    {
        return [
            'requestId' => (string) Str::uuid(),
            'sender'    => $this->sender,
            'locale'    => $this->locale,
            'timestamp' => now()->format('YmdHis'),
            'params'    => $params,
        ];
    }

    private function postSigned(string $path, array $params): array
    {
        $payload = $this->envelope($params);
        $signed  = ChoiceSigner::sign($payload, $this->privateKey);

        $res = $this->http()->post($path, $signed);
        $res->throw();

        $body = $res->json();

        // Verify response signature when present
        if (is_array($body) && isset($body['signature'])) {
            ChoiceSigner::verify($body, $this->privateKey);
            // Non-fatal: log mismatch but don't crash — Choice sometimes omits it
        }

        return is_array($body) ? $body : ['raw' => $res->body()];
    }

    /** Check response code — ChoiceBank uses "00000" for success. */
    public static function isSuccess(array $response): bool
    {
        return ($response['code'] ?? null) === '00000';
    }

    /** Extract the data block from a response. */
    public static function data(array $response): array
    {
        return $response['data'] ?? [];
    }

    // =========================================================================
    // SECTION 1 — PERSONAL ONBOARDING
    // =========================================================================

    /**
     * Full KYC onboarding (current account).
     * Requires employment, income, industry fields.
     */
    public function submitOnboardingRequest(array $kycData): array
    {
        return $this->postSigned('/onboarding/submitOnboardingRequest', $kycData);
    }

    /**
     * Easy/simplified onboarding (wallet account only).
     * Includes frontSidePhoto + selfiePhoto as Base64 inline — no separate uploadMedia needed.
     *
     * Required params: userId, firstName, lastName, birthday (yyyy-MM-dd),
     * gender (0=F/1=M), countryCode, mobile, idType, idNumber, address,
     * kraPin, email, frontSidePhoto (base64), selfiePhoto (base64).
     */
    public function submitEasyOnboardingRequest(array $kycData): array
    {
        return $this->postSigned('/onboarding/v3/submitEasyOnboardingRequest', $kycData);
    }

    /**
     * Upload a KYC document for a pending onboarding request.
     *
     * mediaType codes:
     *   KYCF00001 = National ID front
     *   KYCF00002 = National ID back
     *   KYCF00003 = Passport
     *   KYCF00004 = Alien ID front
     *   KYCF00005 = Alien ID back
     *   KYCF00006 = Selfie
     */
    public function uploadMedia(string $onboardingRequestId, string $mediaType, string $mediaBase64, string $contentType = 'image/jpeg'): array
    {
        return $this->postSigned('/onboarding/uploadMedia', [
            'onboardingRequestId' => $onboardingRequestId,
            'mediaType'           => $mediaType,
            'mediaBase64'         => $mediaBase64,
            'contentType'         => $contentType,
        ]);
    }

    /**
     * Query personal onboarding status.
     * At least one of onboardingRequestId, userId, or mobile is required.
     */
    public function getOnboardingStatus(array $params): array
    {
        return $this->postSigned('/onboarding/getOnboardingStatus', $params);
    }

    /** Get full KYC data for an onboarding request. */
    public function getUserKyc(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/getUserKyc', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    /** Get list of uploaded KYC media URLs. */
    public function getKycMediaList(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/personal/getKycMediaList', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    /**
     * Look up an onboardingRequestId by userId or mobile.
     */
    public function getOnboardingRequestId(array $params): array
    {
        return $this->postSigned('/onboarding/getOnboardingRequestId', $params);
    }

    /**
     * Upgrade a wallet account to a current account.
     * Requires kraPin and photo uploads (frontSidePhoto, selfiePhoto as base64).
     */
    public function walletAccountUpgrade(array $params): array
    {
        return $this->postSigned('/onboarding/walletAccountUpgrade', $params);
    }

    // =========================================================================
    // SECTION 2 — SME / BUSINESS ONBOARDING
    // =========================================================================

    /**
     * Initiate SME onboarding.
     * businessType: 1=Sole Prop, 2=LLC, 3=Partnership, 4=NGO/CBO
     */
    public function applyForSmeOnboarding(array $params): array
    {
        return $this->postSigned('/onboarding/business/applyForSmeOnboarding', $params);
    }

    public function submitStoreOnboardingRequest(array $params): array
    {
        return $this->postSigned('/onboarding/business/submitStoreOnboardingRequest', $params);
    }

    public function submitCompanyOnboardingRequest(array $params): array
    {
        return $this->postSigned('/onboarding/business/submitCompanyOnboardingRequest', $params);
    }

    public function submitPartnershipOnboardingRequest(array $params): array
    {
        return $this->postSigned('/onboarding/business/submitPartnershipOnboardingRequest', $params);
    }

    public function submitOrganisationOnboardingRequest(array $params): array
    {
        return $this->postSigned('/onboarding/business/submitOrganisationOnboardingRequest', $params);
    }

    /**
     * Add an individual director/shareholder to an SME application.
     * Returns memberId.
     */
    public function submitCompanyMember(array $params): array
    {
        return $this->postSigned('/onboarding/business/submitCompanyMember', $params);
    }

    /** Add a corporate shareholder. Returns companyId. */
    public function submitShareholderCompanyMember(array $params): array
    {
        return $this->postSigned('/onboarding/business/submitShareholderCompanyMember', $params);
    }

    public function removeMember(string $onboardingRequestId, string $memberId): array
    {
        return $this->postSigned('/onboarding/business/removeMember', [
            'onboardingRequestId' => $onboardingRequestId,
            'memberId'            => $memberId,
        ]);
    }

    public function uploadBusinessMedia(array $params): array
    {
        return $this->postSigned('/onboarding/business/uploadMedia', $params);
    }

    public function removeBusinessMedia(string $onboardingRequestId, string $fileId): array
    {
        return $this->postSigned('/onboarding/business/removeMedia', [
            'onboardingRequestId' => $onboardingRequestId,
            'fileId'              => $fileId,
        ]);
    }

    /**
     * Submit (action=1) or pull back (action=0) an SME onboarding request.
     */
    public function submitOrPullBackSmeRequest(string $onboardingRequestId, int $action = 1): array
    {
        return $this->postSigned('/onboarding/business/submitOrPullBackRequest', [
            'onboardingRequestId' => $onboardingRequestId,
            'action'              => $action,
        ]);
    }

    public function cancelSmeOnboarding(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/business/cancelOnboardingRequest', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getBusinessOnboardingStatus(array $params): array
    {
        return $this->postSigned('/onboarding/business/getBusinessOnboardingStatus', $params);
    }

    public function getBusinessOnboardingRequestId(array $params): array
    {
        return $this->postSigned('/onboarding/business/getOnboardingRequestId', $params);
    }

    public function getStoreOnboardingInfo(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/business/getStoreOnboardingInfo', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getCompanyOnboardingInfo(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/business/getCompanyOnboardingInfo', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getPartnershipOnboardingInfo(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/business/getPartnershipOnboardingInfo', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getOrganisationOnboardingInfo(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/business/getOrganisationOnboardingInfo', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    // =========================================================================
    // SECTION 3 — JOINT ACCOUNT ONBOARDING
    // =========================================================================

    public function applyForJointAccount(array $params): array
    {
        return $this->postSigned('/onboarding/applyForJointAccount', $params);
    }

    public function addJointAccountOwner(array $params): array
    {
        return $this->postSigned('/onboarding/addJointAccountOwner', $params);
    }

    public function getJointAccountOnboardingInfo(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/getJointAccountOnboardingInfo', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    // =========================================================================
    // SECTION 4 — ACCOUNT MANAGEMENT
    // =========================================================================

    /** Get account details (balance, status, type, shortcode). */
    public function getAccountDetails(string $accountId): array
    {
        return $this->postSigned('/query/getAccountDetails', [
            'accountId' => $accountId,
        ]);
    }

    /** Get all accounts belonging to a userId. */
    public function queryAccountListByUserId(string $userId): array
    {
        return $this->postSigned('/account/queryAccountListByUserId', [
            'userId' => $userId,
        ]);
    }

    /** List accounts with abnormal status (dormant / restricted / frozen). */
    public function getAbnormalAccountList(array $params = []): array
    {
        return $this->postSigned('/query/getAbnormalAccountList', $params);
    }

    /**
     * Validate an account or shortcode before sending money.
     * accountType: 0=Merchant, 1=Paybill, 2=Till, 3=Mobile, 4=Bank
     */
    public function validateAccount(string $accountId, int $accountType = 3): array
    {
        return $this->postSigned('/account/validateAccount', [
            'accountId'   => $accountId,
            'accountType' => $accountType,
        ]);
    }

    /**
     * Open an additional account for an individual.
     * currency = 'KES', 'USD', 'GBP', etc.
     */
    public function individualOpenAccount(string $userId, string $currency): array
    {
        return $this->postSigned('/account/individualOpenAccount', [
            'userId'   => $userId,
            'currency' => $currency,
        ]);
    }

    /**
     * Open an additional account for a business.
     * operatingMode: 'SINGLE' or 'DUAL'
     */
    public function businessOpenAccount(string $userId, string $currency, string $operatingMode = 'SINGLE'): array
    {
        return $this->postSigned('/account/businessOpenAccount', [
            'userId'        => $userId,
            'currency'      => $currency,
            'operatingMode' => $operatingMode,
        ]);
    }

    public function getAccountOpeningStatus(string $applicationId): array
    {
        return $this->postSigned('/account/getAccountOpeningStatus', [
            'applicationId' => $applicationId,
        ]);
    }

    /**
     * Close an individual account. OTP required — this returns applicationId
     * which you must pass to sendOtp() then confirmOperation().
     */
    public function closeIndividualAccount(string $accountId): array
    {
        return $this->postSigned('/account/closeIndividualAccount', [
            'accountId' => $accountId,
        ]);
    }

    /** Generate a shortcode for an account. */
    public function applyForShortCode(string $accountId): array
    {
        return $this->postSigned('/account/applyForShortCode', [
            'accountId' => $accountId,
        ]);
    }

    public function queryForShortCode(string $accountId): array
    {
        return $this->postSigned('/account/queryForShortCode', [
            'accountId' => $accountId,
        ]);
    }

    public function queryAccountByShortCode(string $shortCode): array
    {
        return $this->postSigned('/account/queryAccountByShortCode', [
            'shortCode' => $shortCode,
        ]);
    }

    public function activateAccount(string $accountId): array
    {
        return $this->postSigned('/account/activateAccount', [
            'accountId' => $accountId,
        ]);
    }

    public function addOrUpdateEmail(string $accountId, string $email): array
    {
        return $this->postSigned('/user/addOrUpdateEmail', [
            'accountId' => $accountId,
            'email'     => $email,
        ]);
    }

    public function editSubAccountName(string $accountId, string $name): array
    {
        return $this->postSigned('/account/editSubAccountName', [
            'accountId' => $accountId,
            'name'      => $name,
        ]);
    }

    // =========================================================================
    // SECTION 5 — COMMON / OTP
    // =========================================================================

    /**
     * Request (or resend) an OTP for a pending operation.
     * businessId = the txId / applicationId of the pending transaction.
     */
    public function sendOtp(string $businessId): array
    {
        return $this->postSigned('/common/sendOtp', [
            'businessId' => $businessId,
        ]);
    }

    /**
     * Confirm the OTP code to proceed with the operation.
     * Transitions the operation status to PROCESSING.
     */
    public function confirmOperation(string $businessId, string $verificationCode): array
    {
        return $this->postSigned('/common/confirmOperation', [
            'businessId'       => $businessId,
            'verificationCode' => $verificationCode,
        ]);
    }

    // =========================================================================
    // SECTION 6 — TRANSFERS
    // =========================================================================

    /**
     * Initiate a transfer. Returns txId. OTP required after this call.
     *
     * payeeBankCode controls the rail:
     *   - Internal Choice transfer: leave empty or use internal code
     *   - M-Pesa: use the M-Pesa bank code from /staticData/getBankCodes
     *   - Airtel Money: use Airtel bank code
     *   - Pesalink: use target bank's Pesalink code
     *
     * Required: payerAccountId, payeeBankCode, payeeAccountId, currency, amount, remark
     * Optional: payeeAccountName (for bank counterparties), payeeMobileForNotification
     */
    public function applyForTransfer(array $params): array
    {
        return $this->postSigned('/trans/v2/applyForTransfer', $params);
    }

    /**
     * Bulk transfer (up to 500 transactions). Returns bulkPaymentOrderId.
     * transactions = array of transfer param objects.
     */
    public function generalBulkTransfer(array $transactions, string $remark = ''): array
    {
        return $this->postSigned('/trans/v2/generalBulkTransfer', [
            'transactions' => $transactions,
            'remark'       => $remark,
        ]);
    }

    /** Query bulk transfer results (paginated). */
    public function queryBatchTransactionResult(string $bulkPaymentOrderId, int $pageNo = 1, int $pageSize = 20): array
    {
        return $this->postSigned('/trans/queryBatchTransactionResult', [
            'bulkPaymentOrderId' => $bulkPaymentOrderId,
            'pageNo'             => $pageNo,
            'pageSize'           => $pageSize,
        ]);
    }

    /** Request a transaction reversal. */
    public function txReversal(string $txId, string $reason = ''): array
    {
        return $this->postSigned('/trans/txReversal', [
            'txId'   => $txId,
            'reason' => $reason,
        ]);
    }

    /** Query reversal request status. */
    public function queryTxReversal(string $txId): array
    {
        return $this->postSigned('/trans/queryTxReversal', [
            'txId' => $txId,
        ]);
    }

    // =========================================================================
    // SECTION 7 — M-PESA B2B PAYMENTS
    // =========================================================================

    /**
     * M-Pesa Business-to-Business payment (Paybill or Till/BuyGoods).
     *
     * payType: 0 = Paybill, 1 = Till/BuyGoods
     * payeeReferenceNumber is required for Paybill, ignored for Till.
     * amount must be a whole number (no decimals).
     */
    public function applyForMpesaBusinessTransfer(
        string $payerAccountId,
        string $payeeShortCode,
        int    $payType,
        int    $amount,
        string $description = '',
        string $payeeReferenceNumber = ''
    ): array {
        $params = [
            'payerAccountId' => $payerAccountId,
            'payeeShortCode' => $payeeShortCode,
            'payType'        => $payType,
            'amount'         => $amount,
            'description'    => $description,
        ];

        if ($payType === 0 && $payeeReferenceNumber !== '') {
            $params['payeeReferenceNumber'] = $payeeReferenceNumber;
        }

        return $this->postSigned('/trans/v2/applyForMpesaBusinessTransfer', $params);
    }

    // =========================================================================
    // SECTION 8 — UTILITY PAYMENTS & AIRTIME
    // =========================================================================

    /**
     * Purchase airtime.
     *
     * operator: 'SAFARICOM', 'AIRTEL', 'TELKOM'
     * phoneNumber: recipient's phone (with country code)
     * amount: airtime amount in KES
     */
    public function airtimePayment(
        string $payerAccountId,
        string $phoneNumber,
        string $operator,
        int    $amount
    ): array {
        return $this->postSigned('/utilityPayment/v2/airtimePayment', [
            'payerAccountId' => $payerAccountId,
            'phoneNumber'    => $phoneNumber,
            'operator'       => $operator,
            'amount'         => $amount,
        ]);
    }

    /**
     * Bulk airtime purchase. Returns bulkPaymentOrderId.
     * recipients = array of [phoneNumber, operator, amount] objects.
     */
    public function airtimeBulkPayment(string $payerAccountId, array $recipients): array
    {
        return $this->postSigned('/utilityPayment/v2/airtimeBulkPayment', [
            'payerAccountId' => $payerAccountId,
            'recipients'     => $recipients,
        ]);
    }

    /**
     * Query a utility bill amount before paying.
     * billerCode: DSTV, GOTV, Startimes, water utility codes
     * accountNumber: customer account/meter number
     */
    public function billQuery(string $billerCode, string $accountNumber, array $extra = []): array
    {
        return $this->postSigned('/utilityPayment/billQuery', array_merge([
            'billerCode'    => $billerCode,
            'accountNumber' => $accountNumber,
        ], $extra));
    }

    /**
     * Pay a utility bill.
     *
     * Required: payerAccountId, billerCode, accountNumber, amount
     */
    public function billPayment(array $params): array
    {
        return $this->postSigned('/utilityPayment/v2/billPayment', $params);
    }

    /** Query a single utility payment or airtime status by paymentId. */
    public function paymentQuery(string $paymentId): array
    {
        return $this->postSigned('/utilityPayment/paymentQuery', [
            'paymentId' => $paymentId,
        ]);
    }

    /** Query bulk utility or airtime order results (paginated). */
    public function bulkPaymentQuery(string $bulkPaymentOrderId, int $pageNo = 1, int $pageSize = 20): array
    {
        return $this->postSigned('/utilityPayment/bulkPaymentQuery', [
            'bulkPaymentOrderId' => $bulkPaymentOrderId,
            'pageNo'             => $pageNo,
            'pageSize'           => $pageSize,
        ]);
    }

    // =========================================================================
    // SECTION 9 — FOREIGN EXCHANGE
    // =========================================================================

    /**
     * Get live FX rate.
     * currency: USD, GBP, EUR, CNY, TZS, UGX, RWF
     * operation: 'buy' or 'sell'
     */
    public function getForeignExchangeRate(string $currency, string $operation = 'buy'): array
    {
        return $this->postSigned('/query/getForeignExchangeRate', [
            'currency'  => $currency,
            'operation' => $operation,
        ]);
    }

    /**
     * Initiate a foreign currency exchange.
     *
     * kesAccountId: the KES account
     * fcAccountId: the foreign currency account
     * currency: USD, GBP, EUR, CNY, TZS, UGX, RWF
     * amount: amount to exchange
     * operation: 'buy' (buy FC with KES) or 'sell' (sell FC for KES)
     */
    public function applyForFcExchange(
        string $kesAccountId,
        string $fcAccountId,
        string $currency,
        float  $amount,
        string $operation
    ): array {
        return $this->postSigned('/trans/applyForFcExchange', [
            'kesAccountId' => $kesAccountId,
            'fcAccountId'  => $fcAccountId,
            'currency'     => $currency,
            'amount'       => $amount,
            'operation'    => $operation,
        ]);
    }

    /** Query FX exchange request status. */
    public function getFxRequestStatus(string $applicationId): array
    {
        return $this->postSigned('/query/getFxRequestStatus', [
            'applicationId' => $applicationId,
        ]);
    }

    // =========================================================================
    // SECTION 10 — CNY TRANSFERS
    // =========================================================================

    /**
     * Initiate a CNY transfer. Max 500,000 CNY.
     * Invoice required above 70,000 CNY.
     */
    public function applyForCnyExpress(array $params): array
    {
        return $this->postSigned('/trans/applyForCnyExpress', $params);
    }

    /** List CNY transfer history (paginated). */
    public function getCnyTransList(int $pageNo = 1, int $pageSize = 20, array $filters = []): array
    {
        return $this->postSigned('/query/getCnyTransList', array_merge([
            'pageNo'   => $pageNo,
            'pageSize' => $pageSize,
        ], $filters));
    }

    /** Get CNY transfer details by applicationId. */
    public function getCnyTransferDetails(string $applicationId): array
    {
        return $this->postSigned('/query/getCnyTransferDetails', [
            'applicationId' => $applicationId,
        ]);
    }

    // =========================================================================
    // SECTION 11 — TRANSACTION QUERIES
    // =========================================================================

    /**
     * Get paginated transaction list for an account.
     *
     * filters (all optional):
     *   txType      array  e.g. [] = all types
     *   txStatus    array  e.g. [2, 8] = completed + reversed
     *   startTime   int    UTC milliseconds
     *   endTime     int    UTC milliseconds
     *   pageNo      int    default 1
     *   pageSize    int    default 20
     *   orderByDesc int    1 = newest first
     */
    public function getTransList(string $accountId, array $filters = []): array
    {
        return $this->postSigned('/query/getTransList', array_merge([
            'accountId'   => $accountId,
            'pageNo'      => 1,
            'pageSize'    => 20,
            'orderByDesc' => 1,
        ], $filters));
    }

    /** Get full details for a single transaction by txId. */
    public function getTransResult(string $txId): array
    {
        return $this->postSigned('/query/getTransResult', [
            'txId' => $txId,
        ]);
    }

    // =========================================================================
    // SECTION 12 — ACCOUNT STATEMENTS
    // =========================================================================

    /**
     * Request statement generation. Returns jobId.
     * Max 180-day range. Poll with queryAccountStatement.
     * startTime/endTime = UTC milliseconds.
     */
    public function applyAccountStatement(string $accountId, int $startTime, int $endTime): array
    {
        return $this->postSigned('/statement/applyAccountStatement', [
            'accountId' => $accountId,
            'startTime' => $startTime,
            'endTime'   => $endTime,
        ]);
    }

    /** Poll statement job status. When ready, link is valid 7 days. */
    public function queryAccountStatement(string $jobId): array
    {
        return $this->postSigned('/statement/queryAccountStatement', [
            'jobId' => $jobId,
        ]);
    }

    /** Request statement emailed to account owner. Max 10 per day. */
    public function applyBankAccountStatement(string $accountId, int $startTime, int $endTime): array
    {
        return $this->postSigned('/statement/applyBankAccountStatement', [
            'accountId' => $accountId,
            'startTime' => $startTime,
            'endTime'   => $endTime,
        ]);
    }

    public function queryBankAccountStatement(string $jobId): array
    {
        return $this->postSigned('/statement/queryBankAccountStatement', [
            'jobId' => $jobId,
        ]);
    }

    // =========================================================================
    // SECTION 13 — REPORTING
    // =========================================================================

    /**
     * Get BaaS channel closing balances for a given date.
     * queryDate format: yyyy-MM-dd
     */
    public function queryClosingBalance(string $queryDate): array
    {
        return $this->postSigned('/report/queryClosingBalance', [
            'queryDate' => $queryDate,
        ]);
    }
}
