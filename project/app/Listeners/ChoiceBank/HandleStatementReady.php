<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\StatementReady;
use App\Models\ProviderTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0009 — Account statement ready.
 *
 * ChoiceBank has generated the requested statement.
 * We find the user who requested it and notify them with the download link.
 * The link is valid for 7 days.
 */
class HandleStatementReady implements ShouldQueue
{
    public function handle(StatementReady $event): void
    {
        $data      = $event->data;
        $jobId     = $data['jobId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        $fileUrl   = $data['fileUrl'] ?? $data['url'] ?? null;

        if (!$fileUrl) {
            Log::warning('HandleStatementReady: no fileUrl in callback', $data);
            return;
        }

        // Find the wallet owner so we can notify them
        $user = null;
        if ($accountId) {
            $wallet = Wallet::where('wallet_external_provider_number', $accountId)
                ->where('wallet_external_provider', 'ChoiceBank')
                ->first();

            if ($wallet) {
                $user = User::find($wallet->user_id);
            }
        }

        // Mark the ProviderTransaction as complete if one exists
        if ($jobId) {
            ProviderTransaction::where('provider_tx_id', $jobId)
                ->whereNull('completed_at')
                ->each(fn($pt) => $pt->markCompleted('READY', $data));
        }

        Log::info('HandleStatementReady: statement available', [
            'jobId'     => $jobId,
            'accountId' => $accountId,
            'fileUrl'   => $fileUrl,
        ]);

        // Notify the user
        if ($user) {
            try {
                @mailSend('statement_ready', [
                    'file_url'   => $fileUrl,
                    'expires_at' => now()->addDays(7)->toDateString(),
                ], $user);
            } catch (\Throwable $e) {
            }
        }
    }
}
