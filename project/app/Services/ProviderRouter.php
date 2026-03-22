<?php

namespace App\Services;

use App\Models\ProviderRoutingRule;
use App\PaymentProviders\ProviderContract;
use App\PaymentProviders\ProviderResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resolves the correct payment provider driver for a given operation
 * and executes the action — transparently to the caller.
 *
 * Usage:
 *   // Simple: let the router pick the provider and call it
 *   $response = ProviderRouter::run('send_mpesa', 'KES', 'KE', fn($p) => $p->sendToMobile([...]));
 *
 *   // Or: resolve the driver and call it yourself
 *   $driver = ProviderRouter::resolve('send_mpesa', 'KES', 'KE');
 *   $response = $driver->sendToMobile([...]);
 *
 * If the primary provider fails and a fallback is configured,
 * the router automatically retries on the fallback.
 */
class ProviderRouter
{
    /**
     * Resolve the driver for a given operation, currency, and country.
     * Throws if no active rule is found.
     */
    public static function resolve(
        string  $operation,
        ?string $currencyCode = null,
        ?string $countryCode  = null
    ): ProviderContract {
        $rule = self::findRule($operation, $currencyCode, $countryCode);

        if (!$rule) {
            throw new RuntimeException(
                "No active routing rule for operation [{$operation}] currency [{$currencyCode}] country [{$countryCode}]"
            );
        }

        return self::instantiate($rule->provider);
    }

    /**
     * Resolve driver, execute the callback, and automatically fall back on failure.
     *
     * @param callable(ProviderContract): ProviderResponse $action
     */
    public static function run(
        string   $operation,
        callable $action,
        ?string  $currencyCode = null,
        ?string  $countryCode  = null
    ): ProviderResponse {
        $rule = self::findRule($operation, $currencyCode, $countryCode);

        if (!$rule) {
            return ProviderResponse::failure(
                "No provider configured for [{$operation}]"
            );
        }

        // --- Try primary provider ---
        try {
            $driver   = self::instantiate($rule->provider);
            $response = $action($driver);

            if ($response->success || $response->needsOtp) {
                return $response;
            }

            Log::warning('ProviderRouter: primary provider failed', [
                'operation' => $operation,
                'provider'  => $rule->provider->slug,
                'error'     => $response->error,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProviderRouter: primary provider threw exception', [
                'operation' => $operation,
                'provider'  => $rule->provider->slug,
                'error'     => $e->getMessage(),
            ]);

            $response = ProviderResponse::failure($e->getMessage());
        }

        // --- Try fallback provider if configured ---
        if ($rule->fallbackProvider) {
            Log::info('ProviderRouter: attempting fallback', [
                'operation' => $operation,
                'fallback'  => $rule->fallbackProvider->slug,
            ]);

            try {
                $fallbackDriver = self::instantiate($rule->fallbackProvider);
                return $action($fallbackDriver);
            } catch (\Throwable $e) {
                Log::error('ProviderRouter: fallback provider threw exception', [
                    'operation' => $operation,
                    'fallback'  => $rule->fallbackProvider->slug,
                    'error'     => $e->getMessage(),
                ]);

                return ProviderResponse::failure($e->getMessage());
            }
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function findRule(
        string  $operation,
        ?string $currencyCode,
        ?string $countryCode
    ): ?ProviderRoutingRule {
        // Try exact match first (operation + currency + country)
        // then progressively broaden scope
        $queries = [
            [strtoupper($currencyCode ?? ''), strtoupper($countryCode ?? '')],
            [strtoupper($currencyCode ?? ''), null],
            [null, strtoupper($countryCode ?? '')],
            [null, null],
        ];

        foreach ($queries as [$currency, $country]) {
            $rule = ProviderRoutingRule::where('operation', $operation)
                ->where('currency_code', $currency)
                ->where('country_code', $country)
                ->where('is_active', true)
                ->with(['provider', 'fallbackProvider'])
                ->orderBy('priority')
                ->first();

            if ($rule && $rule->provider?->is_active) {
                return $rule;
            }
        }

        return null;
    }

    private static function instantiate(\App\Models\PaymentProvider $provider): ProviderContract
    {
        $class = $provider->driver_class;

        if (!class_exists($class)) {
            throw new RuntimeException("Provider driver class [{$class}] not found");
        }

        $driver = app($class, ['provider' => $provider]);

        if (!$driver instanceof ProviderContract) {
            throw new RuntimeException("[{$class}] must implement ProviderContract");
        }

        return $driver;
    }
}
