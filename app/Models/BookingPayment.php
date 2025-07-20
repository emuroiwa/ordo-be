<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'booking_id',
        'amount',
        'currency',
        'payment_method',
        'payment_provider',
        'provider_payment_id',
        'status',
        'processed_at',
        'provider_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'provider_response' => 'array',
    ];

    /**
     * Relationships
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    /**
     * Accessors
     */
    public function getFormattedAmountAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->amount, 2);
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    public function getIsRefundedAttribute(): bool
    {
        return $this->status === 'refunded';
    }

    /**
     * Business Logic Methods
     */
    public function markAsCompleted(array $providerResponse = []): bool
    {
        return $this->update([
            'status' => 'completed',
            'processed_at' => now(),
            'provider_response' => $providerResponse,
        ]);
    }

    public function markAsFailed(array $providerResponse = []): bool
    {
        return $this->update([
            'status' => 'failed',
            'processed_at' => now(),
            'provider_response' => $providerResponse,
        ]);
    }

    public function markAsRefunded(array $providerResponse = []): bool
    {
        return $this->update([
            'status' => 'refunded',
            'processed_at' => now(),
            'provider_response' => $providerResponse,
        ]);
    }
}
