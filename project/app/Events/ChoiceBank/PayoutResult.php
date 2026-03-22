<?php

namespace App\Events\ChoiceBank;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when ChoiceBank sends callback code 0002 (outbound transfer result).
 * data keys: txId, status, amount, currency, payeeAccountId, remark, etc.
 */
class PayoutResult
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $data,
        public readonly array $rawPayload,
    ) {}
}
