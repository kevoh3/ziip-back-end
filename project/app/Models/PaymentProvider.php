<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'driver_class',
        'config',
        'capabilities',
        'supported_countries',
        'supported_currencies',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'config'               => 'encrypted:array',
        'capabilities'         => 'array',
        'supported_countries'  => 'array',
        'supported_currencies' => 'array',
        'is_active'            => 'boolean',
        'priority'             => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function routingRules(): HasMany
    {
        return $this->hasMany(ProviderRoutingRule::class, 'provider_id');
    }

    public function providerTransactions(): HasMany
    {
        return $this->hasMany(ProviderTransaction::class, 'provider_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithCapability($query, string $capability)
    {
        return $query->whereJsonContains('capabilities', $capability);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }

    public function supportsCountry(string $countryCode): bool
    {
        $countries = $this->supported_countries ?? [];
        return empty($countries) || in_array(strtoupper($countryCode), $countries);
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $currencies = $this->supported_currencies ?? [];
        return empty($currencies) || in_array(strtoupper($currencyCode), $currencies);
    }
}
