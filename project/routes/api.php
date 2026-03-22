<?php

use App\Http\Controllers\Api\Callbacks\ChoiceBankCallbackController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\User\AuthController;
use App\Http\Controllers\Api\User\UserController;
use App\Http\Controllers\Front\FrontendController;
use App\Http\Controllers\Api\User\EscrowController;
use App\Http\Controllers\Api\User\DepositController;
use App\Http\Controllers\Api\User\VoucherController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\User\TransferController;
use App\Http\Controllers\Api\Merchant\LoginController;
use App\Http\Controllers\Api\User\MakePaymentController;
use App\Http\Controllers\Api\Merchant\MerchantController;
use App\Http\Controllers\Api\User\RequestMoneyController;
use App\Http\Controllers\Api\User\ExchangeMoneyController;
use App\Http\Controllers\Api\User\ManageInvoiceController;
use App\Http\Controllers\Api\Merchant\WithdrawalController;
use App\Http\Controllers\Api\User\WithdrawalController as UserWithdrawalController;
use App\Http\Controllers\Api\User\ExternalSendController;
use App\Http\Controllers\Api\User\AirtimeController;
use App\Http\Controllers\Api\User\BillPaymentController;
use App\Http\Controllers\Api\User\FxController;


Route::get('qr-code-scan/{email}',   [FrontendController::class, 'scanQR']);
Route::get('module',   [FrontendController::class, 'moduleData']);
Route::get('registration-countries', [FrontendController::class, 'countries']);


Route::prefix('user')->middleware('maintenance')->group(function () {
    Route::post('login',                           [AuthController::class, 'login']);
    Route::get('document-type',      [AuthController::class, 'documentType']);
    Route::post('register',                        [AuthController::class, 'register']);
    Route::post('forgot-password',                 [AuthController::class, 'forgotPasswordSubmit']);
    Route::post('forgot-password/verify-code',     [AuthController::class, 'verifyCodeSubmit']);
    Route::post('reset-password',                  [AuthController::class, 'resetPasswordSubmit']);

    Route::post('verify-email',                    [AuthController::class, 'verifyEmailSubmit'])->middleware('auth:sanctum');

    Route::get('resend/verify-email/code',         [AuthController::class, 'verifyEmailResendCode'])->name('api.user.verify.email.resend')->middleware('auth:sanctum');

    Route::post('two-step/verification',           [AuthController::class, 'twoStepVerify'])->middleware('auth:sanctum');
    Route::post('send/two-step/verify-code/',      [AuthController::class, 'twoStepsendCode'])->middleware('auth:sanctum');
    Route::post('/two-step/code/verify',            [AuthController::class, 'twoStepCodeVerify'])->middleware('auth:sanctum');
    Route::get('resend/two-step/verify-code',      [AuthController::class, 'twoStepResendCode'])->middleware('auth:sanctum');
    Route::middleware(['auth:sanctum', 'email_verify', 'twostep_api'])->group(function () {
        Route::get('settings',                [AuthController::class, 'settings']);
        Route::post('logout',                  [AuthController::class, 'logout']);
        Route::get('/dashboard',                      [UserController::class, 'index']);
        Route::get('/generate-qrcode',               [UserController::class, 'generateQR']);
        Route::get('/user-info',                     [UserController::class, 'userInfo']);
        Route::post('/wallet/{id}/sync-balance',     [UserController::class, 'syncWalletBalance']);
        Route::get('kyc-form-data',            [UserController::class, 'kycForm']);
        Route::post('kyc-form',                [UserController::class, 'kycFormSubmit']);
        Route::get('transactions',             [UserController::class, 'transactions']);
        Route::get('transaction/details/{id}', [UserController::class, 'trxDetails']);
        Route::post('profile-settings',        [UserController::class, 'profileSubmit']);
        Route::post('change-password',         [UserController::class, 'changePass']);
        Route::middleware(['module', 'kyc'])->group(function () {
            //transfer-money
            Route::get('transfer-money',    [TransferController::class, 'transferForm']);
            Route::post('transfer-money',   [TransferController::class, 'submitTransfer']);
            //Request Money
            Route::get('request-money',     [RequestMoneyController::class, 'requestForm']);
            Route::post('request-money',    [RequestMoneyController::class, 'requestSubmit']);
            //exchange money
            Route::get('exchange-money',    [ExchangeMoneyController::class, 'exchangeForm']);
            Route::post('exchange-money',   [ExchangeMoneyController::class, 'submitExchange']);

            // merchant payment
            Route::get('make-payment',      [MakePaymentController::class, 'paymentForm']);
            Route::post('make-payment',     [MakePaymentController::class, 'submitPayment']);

            //voucher
            Route::get('create-voucher',    [VoucherController::class, 'create']);
            Route::post('create-voucher',   [VoucherController::class, 'submit']);

            //withdraw
            Route::get('withdraw-money',            [UserWithdrawalController::class, 'withdrawForm']);
            Route::post('withdraw-money',           [UserWithdrawalController::class, 'withdrawSubmit']);
            Route::post('withdraw-money/confirm-otp', [UserWithdrawalController::class, 'confirmOtp']);
            Route::get('cash-out',                  [UserWithdrawalController::class, 'cashOutForm']);
            Route::post('cash-out',                 [UserWithdrawalController::class, 'cashOut']);
            Route::post('check-ajent',              [UserWithdrawalController::class, 'checkReceiver']);

            // External sends (M-Pesa, Airtel, Bank) — provider-agnostic
            Route::get('send-to-mobile',            [ExternalSendController::class, 'sendToMobileForm']);
            Route::post('send-to-mobile',           [ExternalSendController::class, 'sendToMobile']);
            Route::get('send-to-bank',              [ExternalSendController::class, 'sendToBankForm']);
            Route::post('send-to-bank',             [ExternalSendController::class, 'sendToBank']);
            Route::post('confirm-send-otp',         [ExternalSendController::class, 'confirmOtp']);

            // Airtime — provider-agnostic
            Route::get('buy-airtime',               [AirtimeController::class, 'form']);
            Route::post('buy-airtime',              [AirtimeController::class, 'submit']);

            // Bill payments — provider-agnostic
            Route::get('pay-bill',                  [BillPaymentController::class, 'form']);
            Route::post('bill-query',               [BillPaymentController::class, 'billQuery']);
            Route::post('pay-bill',                 [BillPaymentController::class, 'payBill']);

            // FX / Currency exchange — provider-agnostic
            Route::get('fx-rate',                   [FxController::class, 'getRate']);
            Route::get('fx-exchange',               [FxController::class, 'form']);
            Route::post('fx-exchange',              [FxController::class, 'exchange']);

            //invoice
            Route::get('create-invoice',    [ManageInvoiceController::class, 'create']);
            Route::post('create-invoice',   [ManageInvoiceController::class, 'store']);

            //escrow
            Route::get('make-escrow',       [EscrowController::class, 'create']);
            Route::post('make-escrow',      [EscrowController::class, 'store']);

            //deposit
            Route::get('deposit',           [DepositController::class, 'index'])->name('deposit.index');
        });

        //transfer-money
        Route::get('transfer-money/history',  [TransferController::class, 'transferHistory']);
        Route::post('check-receiver',         [TransferController::class, 'checkReceiver']);

        //Request Money
        Route::get('money-request',           [RequestMoneyController::class, 'moneyRequests']);
        Route::get('sent-money-requests',     [RequestMoneyController::class, 'sentRequests']);
        Route::get('received-money-requests', [RequestMoneyController::class, 'receivedRequests']);
        Route::post('accept-money-request',   [RequestMoneyController::class, 'acceptRequest']);
        Route::post('reject-money-request',   [RequestMoneyController::class, 'rejectRequest']);

        //exchange money
        Route::get('exchange-money/history',  [ExchangeMoneyController::class, 'exchangeHistory']);

        //payment history
        Route::get('payment/history',   [MakePaymentController::class, 'paymentHistory']);
        Route::post('check-merchant',   [MakePaymentController::class, 'checkMerchant']);

        //Reedem voucher
        Route::get('vouchers',          [VoucherController::class, 'vouchers']);
        Route::get('redeem-voucher',    [VoucherController::class, 'reedemForm']);
        Route::post('redeem-voucher',   [VoucherController::class, 'reedemSubmit']);
        Route::get('redeemed-history',  [VoucherController::class, 'reedemHistory']);

        //withdraw
        Route::get('withdraw-methods',  [UserWithdrawalController::class, 'methods']);
        Route::get('withdraw-history',  [UserWithdrawalController::class, 'history']);
        Route::post('check/agent',      [UserWithdrawalController::class, 'checkReceiver']);

        // New feature history
        Route::get('airtime-history',   [AirtimeController::class, 'history']);
        Route::get('bill-history',      [BillPaymentController::class, 'history']);
        Route::get('fx-history',        [FxController::class, 'history']);

        //support ticket
        Route::get('support/tickets',                        [SupportTicketController::class, 'index'])->name('api.user.tickets');
        Route::get('support/ticket/messages/{ticket_num}',   [SupportTicketController::class, 'messages'])->name('api.user.ticket.messages');
        Route::post('open/support/ticket',                   [SupportTicketController::class, 'openTicket'])->name('api.user.ticket.open');
        Route::post('reply/ticket/{ticket_num}',             [SupportTicketController::class, 'replyTicket'])->name('api.user.ticket.reply');

        //invoice
        Route::get('invoices',                  [ManageInvoiceController::class, 'index']);
        Route::post('invoice/pay-status',       [ManageInvoiceController::class, 'payStatus']);
        Route::post('invoice/publish-status',   [ManageInvoiceController::class, 'publishStatus']);
        Route::get('invoices-edit/{id}',        [ManageInvoiceController::class, 'edit']);
        Route::post('invoices-update/{id}',     [ManageInvoiceController::class, 'update']);
        Route::get('invoice-cancel/{id}',       [ManageInvoiceController::class, 'cancel']);
        Route::get('invoice/send-mail/{id}',    [ManageInvoiceController::class, 'sendToMail']);
        Route::get('invoice/view/{number}',     [ManageInvoiceController::class, 'view']);

        //escrow
        Route::get('my-escrow',              [EscrowController::class, 'index']);
        Route::get('escrow-pending',         [EscrowController::class, 'pending']);
        Route::get('escrow-dispute/{id}',    [EscrowController::class, 'disputeForm']);
        Route::post('escrow-dispute/{id}',   [EscrowController::class, 'disputeStore']);
        Route::get('release-escrow/{id}',    [EscrowController::class, 'release']);
        Route::get('file-download/{id}',     [EscrowController::class, 'fileDownload'])->name('user.api.escrow.file.download');

        //deposit
        Route::post('deposit/submit',        [DepositController::class, 'depositSubmit']);
        Route::post('payment-submit',        [DepositController::class, 'depositPayment'])->name('deposit.payment');
        Route::get('deposit/history',        [DepositController::class, 'dipositHistory'])->name('deposit.history');
        Route::get('gateway-methods',        [DepositController::class, 'methods'])->name('gateway.methods');
        Route::post('deposit-process',       [DepositController::class, 'depositProcess'])->name('deposit.process');

        //twostep
        Route::post('/two-step/send-code',        [UserController::class, 'twoStepSendCode']);
        Route::post('/two-step/verify',           [UserController::class, 'twoStepVerifySubmit']);
    });
});
//merchant
Route::prefix('merchant')->middleware('maintenance')->group(function () {

    Route::post('login',                           [LoginController::class, 'login']);
    Route::post('register',                        [LoginController::class, 'register']);
    Route::post('forgot-password',                 [LoginController::class, 'forgotPasswordSubmit']);
    Route::post('forgot-password/verify-code',     [LoginController::class, 'verifyCodeSubmit']);
    Route::post('reset-password',                  [LoginController::class, 'resetPasswordSubmit']);

    Route::post('verify-email',                    [LoginController::class, 'verifyEmailSubmit'])->middleware('auth:sanctum');

    Route::get('resend/verify-email/code',         [LoginController::class, 'verifyEmailResendCode'])->name('api.merchant.verify.email.resend')->middleware('auth:sanctum');

    Route::post('two-step/verification',           [LoginController::class, 'twoStepVerify'])->middleware('auth:sanctum');
    Route::get('resend/two-step/verify-code',      [LoginController::class, 'twoStepResendCode'])->middleware('auth:sanctum');

    Route::get('/logout',                          [LoginController::class, 'logout'])->name('logout')->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'merchant_email_verify', 'twostep_api'])->group(function () {
        Route::get('/dashboard',                  [MerchantController::class, 'dashboard'])->name('dashboard');
        Route::get('/generate-qrcode',            [MerchantController::class, 'generateQR'])->name('qr');

        Route::get('transactions',                [MerchantController::class, 'transactions']);
        Route::get('transaction/details/{id}',    [MerchantController::class, 'trxDetails']);

        Route::post('/profile-setting',           [MerchantController::class, 'profileUpdate']);
        Route::post('/change-password',           [MerchantController::class, 'updatePassword']);

        //kyc form
        Route::get('kyc-form-data',               [MerchantController::class, 'kycForm']);
        Route::post('kyc-form',                   [MerchantController::class, 'kycFormSubmit']);

        //twostep
        Route::post('/two-step/send-code',        [MerchantController::class, 'twoStepSendCode']);
        Route::post('/two-step/verify',           [MerchantController::class, 'twoStepVerifySubmit']);


        Route::get('withdraw-methods',            [WithdrawalController::class, 'methods']);
        Route::get('withdraw-history',            [WithdrawalController::class, 'history']);

        Route::post('generate-api-key',           [MerchantController::class, 'apiKeyGenerate']);
        Route::get('service-mode',                [MerchantController::class, 'serviceMode']);

        //support ticket
        Route::get('support/tickets',                        [SupportTicketController::class, 'index'])->name('api.merchant.tickets');
        Route::get('support/ticket/messages/{ticket_num}',   [SupportTicketController::class, 'messages'])->name('api.merchant.ticket.messages');
        Route::post('open/support/ticket',                   [SupportTicketController::class, 'openTicket'])->name('api.merchant.ticket.open');
        Route::post('reply/ticket/{ticket_num}',             [SupportTicketController::class, 'replyTicket'])->name('api.merchant.ticket.reply');
    });

});
//callbacks
Route::prefix('callbacks')->middleware('maintenance')->group(function () {

    // Main webhook endpoint (most APIs POST here)
    Route::post('choicebank', [ChoiceBankCallbackController::class, 'handle'])
        ->name('callbacks.choicebank');

    // Optional: some providers send a GET ping/challenge to verify endpoint
    Route::get('choicebank', [ChoiceBankCallbackController::class, 'verify'])
        ->name('callbacks.choicebank.verify');
});
