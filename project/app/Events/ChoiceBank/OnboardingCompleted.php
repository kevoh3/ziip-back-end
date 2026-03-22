<?php

namespace App\Events\ChoiceBank;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when ChoiceBank sends callback code 0001 (personal onboarding result).
 * data keys: onboardingRequestId, status, userId, etc.
 */
class OnboardingCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $data,
        public readonly array $rawPayload,
    ) {}
}
