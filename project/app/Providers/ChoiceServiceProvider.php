<?php


namespace App\Providers;

use App\Services\Choice\ChoiceOnboardingService;
use Illuminate\Support\ServiceProvider;

class ChoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChoiceOnboardingService::class, function () {
            $cfg = config('services.choice');

            return new ChoiceOnboardingService(
                $cfg['base_url'],
                $cfg['sender'],
                $cfg['private_key'],
                $cfg['locale'],
                $cfg['timeout'],
            );
        });
    }
}
