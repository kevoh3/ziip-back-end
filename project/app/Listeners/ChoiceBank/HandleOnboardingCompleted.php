<?php

namespace App\Listeners\ChoiceBank;

use App\Events\ChoiceBank\OnboardingCompleted;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Event code 0001 — Personal onboarding result.
 *
 * When ChoiceBank approves (or rejects) a KYC submission we:
 *   APPROVED → kyc_status=1, store accountId on wallet
 *   REJECTED → kyc_status=0 (reset), store rejection reason
 */
class HandleOnboardingCompleted implements ShouldQueue
{
    public function handle(OnboardingCompleted $event): void
    {
        $data = $event->data;

        $onboardingRequestId = $data['onboardingRequestId'] ?? null;
        $cbStatus            = strtoupper($data['status'] ?? '');
        $accountId           = $data['accountId'] ?? null; // ChoiceBank account ID on approval

        if (!$onboardingRequestId) {
            Log::warning('HandleOnboardingCompleted: missing onboardingRequestId', $data);
            return;
        }

        $user = User::where('choice_onboarding_request_id', $onboardingRequestId)->first();

        if (!$user) {
            Log::warning('HandleOnboardingCompleted: no user found', [
                'onboardingRequestId' => $onboardingRequestId,
            ]);
            return;
        }

        // Update ChoiceBank onboarding fields
        $user->choice_onboarding_status     = $cbStatus;
        $user->choice_onboarding_updated_at = now();

        if ($cbStatus === 'APPROVED' || $cbStatus === 'SUCCESS') {
            $user->kyc_status = 1; // verified

            // Store accountId on the user's primary wallet so it can be used for transfers
            if ($accountId) {
                $this->linkChoiceBankAccount($user, $accountId, $data);
            }

            Log::info('HandleOnboardingCompleted: KYC approved', [
                'user_id'   => $user->id,
                'accountId' => $accountId,
            ]);

        } elseif (in_array($cbStatus, ['REJECTED', 'FAILED', 'DECLINED'])) {
            $user->kyc_status = 0; // reset — allow resubmission

            $rejectReason = $data['rejectReason'] ?? $data['reason'] ?? 'KYC verification failed';
            $user->kyc_reject_reason = $rejectReason;

            Log::info('HandleOnboardingCompleted: KYC rejected', [
                'user_id' => $user->id,
                'reason'  => $rejectReason,
            ]);
        }

        $user->save();

        // Notify user via email if template exists
        try {
            $status = ($cbStatus === 'APPROVED' || $cbStatus === 'SUCCESS') ? 'approved' : 'rejected';
            @mailSend('kyc_' . $status, ['name' => $user->name], $user);
        } catch (\Throwable $e) {
            // Email failure must not break the listener
        }
    }

    /**
     * Link the ChoiceBank accountId to the user's primary KES wallet.
     * Also creates the wallet if it doesn't exist yet.
     */
    private function linkChoiceBankAccount(User $user, string $accountId, array $data): void
    {
        // Find KES currency — ChoiceBank operates in KES primarily
        $currency = \App\Models\Currency::where('code', 'KES')->first();
        if (!$currency) return;

        $wallet = Wallet::where('user_id', $user->id)
            ->where('user_type', 1)
            ->where('currency_id', $currency->id)
            ->first();

        if (!$wallet) {
            $wallet = Wallet::create([
                'user_id'     => $user->id,
                'user_type'   => 1,
                'currency_id' => $currency->id,
                'balance'     => 0,
                'is_primary'  => true,
            ]);
        }

        // Use the clean linkToProvider() method — works for any provider
        $wallet->linkToProvider('choicebank', $accountId, $data);
    }
}
