<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\BulkTransferResult;
use App\Models\ProviderTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0008 — Bulk transfer batch completed.
 *
 * Updates the ProviderTransaction records for the bulk order.
 * Individual item results can be queried via ChoiceBankService::queryBatchTransactionResult().
 */
class HandleBulkTransferResult implements ShouldQueue
{
    public function handle(BulkTransferResult $event): void
    {
        $data              = $event->data;
        $bulkOrderId       = $data['bulkPaymentOrderId'] ?? null;
        $status            = strtoupper($data['status'] ?? '');
        $successCount      = (int) ($data['successCount'] ?? 0);
        $failCount         = (int) ($data['failCount'] ?? 0);

        if (!$bulkOrderId) {
            Log::warning('HandleBulkTransferResult: missing bulkPaymentOrderId', $data);
            return;
        }

        // Mark all ProviderTransactions for this bulk order as completed
        $updated = ProviderTransaction::where('provider_tx_id', $bulkOrderId)
            ->whereNull('completed_at')
            ->get();

        foreach ($updated as $pt) {
            $pt->markCompleted($status, $data);
        }

        Log::info('HandleBulkTransferResult: bulk order processed', [
            'bulkOrderId'  => $bulkOrderId,
            'status'       => $status,
            'successCount' => $successCount,
            'failCount'    => $failCount,
            'updated'      => $updated->count(),
        ]);
    }
}
