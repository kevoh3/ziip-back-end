<?php

namespace App\Events\ChoiceBank;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when ChoiceBank sends callback code 0022 (merchant pay-out result).
 * data keys: txId, merchantAccountId, amount, currency, status, etc.
 */
class MerchantPayout
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $data,
        public readonly array $rawPayload,
    ) {}
}
