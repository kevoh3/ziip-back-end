<?php

namespace App\PaymentProviders;

/**
 * Standard response returned by every provider driver method.
 * Controllers and services work only with this — never with raw provider responses.
 */
final class ProviderResponse
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?string $txId    = null,
        public readonly ?string $status  = null,
        public readonly array   $data    = [],
        public readonly ?string $error   = null,
        // When the provider requires OTP before the tx is processed
        public readonly bool    $needsOtp = false,
    ) {}

    public static function success(array $data = [], ?string $txId = null, ?string $status = null): self
    {
        return new self(success: true, txId: $txId, status: $status, data: $data);
    }

    public static function pendingOtp(string $txId, array $data = []): self
    {
        return new self(success: true, txId: $txId, status: 'PENDING_OTP', data: $data, needsOtp: true);
    }

    public static function failure(string $error, array $data = []): self
    {
        return new self(success: false, error: $error, data: $data);
    }

    public function toArray(): array
    {
        return [
            'success'   => $this->success,
            'tx_id'     => $this->txId,
            'status'    => $this->status,
            'data'      => $this->data,
            'error'     => $this->error,
            'needs_otp' => $this->needsOtp,
        ];
    }
}
