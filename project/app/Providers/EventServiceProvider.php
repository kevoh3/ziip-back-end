<?php

namespace App\Providers;

use App\Events\ChoiceBank\BulkTransferResult;
use App\Events\ChoiceBank\FxResult;
use App\Events\ChoiceBank\MerchantPayIn;
use App\Events\ChoiceBank\MerchantPayout;
use App\Events\ChoiceBank\OnboardingCompleted;
use App\Events\ChoiceBank\PayInReceived;
use App\Events\ChoiceBank\PayoutResult;
use App\Events\ChoiceBank\StatementReady;
use App\Listeners\ChoiceBank\HandleBulkTransferResult;
use App\Listeners\ChoiceBank\HandleFxResult;
use App\Listeners\ChoiceBank\HandleMerchantPayIn;
use App\Listeners\ChoiceBank\HandleMerchantPayout;
use App\Listeners\ChoiceBank\HandleOnboardingCompleted;
use App\Listeners\ChoiceBank\HandlePayInReceived;
use App\Listeners\ChoiceBank\HandlePayoutResult;
use App\Listeners\ChoiceBank\HandleStatementReady;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // ChoiceBank webhook callbacks
        OnboardingCompleted::class => [HandleOnboardingCompleted::class],
        PayoutResult::class        => [HandlePayoutResult::class],
        PayInReceived::class       => [HandlePayInReceived::class],
        MerchantPayIn::class       => [HandleMerchantPayIn::class],
        BulkTransferResult::class  => [HandleBulkTransferResult::class],
        StatementReady::class      => [HandleStatementReady::class],
        FxResult::class            => [HandleFxResult::class],
        MerchantPayout::class      => [HandleMerchantPayout::class],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
