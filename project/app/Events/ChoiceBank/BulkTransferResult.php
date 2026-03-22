<?php

namespace App\Events\ChoiceBank;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when ChoiceBank sends callback code 0008 (bulk transfer completed).
 * data keys: bulkPaymentOrderId, totalCount, successCount, failCount, etc.
 */
class BulkTransferResult
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $data,
        public readonly array $rawPayload,
    ) {}
}
