<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'provider_id',
        'provider_tx_id',
        'operation',
        'provider_status',
        'request_payload',
        'response_payload',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'completed_at'     => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }

    public function isPending(): bool
    {
        return is_null($this->completed_at);
    }

    public function markCompleted(string $status, array $responsePayload = []): void
    {
        $this->update([
            'provider_status'  => $status,
            'response_payload' => $responsePayload,
            'completed_at'     => now(),
        ]);
    }
}
