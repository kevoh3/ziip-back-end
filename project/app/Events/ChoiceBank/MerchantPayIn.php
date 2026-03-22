<?php

namespace App\Events\ChoiceBank;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when ChoiceBank sends callback code 0004 (merchant pay-in received).
 * data keys: txId, merchantAccountId, amount, currency, payerInfo, etc.
 */
class MerchantPayIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $data,
        public readonly array $rawPayload,
    ) {}
}
