<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderRoutingRule extends Model
{
    protected $fillable = [
        'operation',
        'provider_id',
        'fallback_provider_id',
        'currency_code',
        'country_code',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority'  => 'integer',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }

    public function fallbackProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'fallback_provider_id');
    }
}
