<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class Booking extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id',
        'vendor_id',
        'service_id',
        'booking_reference',
        'status',
        'scheduled_at',
        'duration_minutes',
        'total_amount',
        'deposit_amount',
        'currency',
        'customer_notes',
        'vendor_notes',
        'location_type',
        'service_address',
        'payment_status',
        'payment_method',
        'payment_reference',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
        // Guest booking fields
        'guest_email',
        'guest_phone',
        'guest_name',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'service_address' => 'array',
        'total_amount' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
    ];

    // Boot method to generate unique booking reference
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($booking) {
            if (empty($booking->booking_reference)) {
                $booking->booking_reference = static::generateBookingReference();
            }
        });
    }

    /**
     * Generate unique booking reference
     */
    public static function generateBookingReference(): string
    {
        do {
            $reference = 'BK' . date('Y') . strtoupper(uniqid());
        } while (static::where('booking_reference', $reference)->exists());
        
        return $reference;
    }

    /**
     * Relationships
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(BookingReview::class);
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('scheduled_at', '>', now())
                    ->whereIn('status', ['pending', 'confirmed']);
    }

    public function scopePast($query)
    {
        return $query->where('scheduled_at', '<', now());
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Mutators and Accessors
     */
    public function getEndTimeAttribute(): Carbon
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }

    public function getFormattedPriceAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->total_amount, 2);
    }

    public function getIsUpcomingAttribute(): bool
    {
        return $this->scheduled_at->isFuture() && in_array($this->status, ['pending', 'confirmed']);
    }

    public function getCanBeCancelledAttribute(): bool
    {
        if (!$this->isUpcoming) {
            return false;
        }

        // Can be cancelled up to 24 hours before scheduled time
        return $this->scheduled_at->diffInHours(now()) >= 24;
    }

    public function getCanBeRescheduledAttribute(): bool
    {
        if (!$this->isUpcoming) {
            return false;
        }

        // Can be rescheduled up to 12 hours before scheduled time
        return $this->scheduled_at->diffInHours(now()) >= 12;
    }

    public function getDepositPercentageAttribute(): float
    {
        // Check for null, empty, or zero values
        if (!$this->deposit_amount || !$this->total_amount || $this->total_amount == 0) {
            return 0;
        }

        return ($this->deposit_amount / $this->total_amount) * 100;
    }

    /**
     * Business Logic Methods
     */
    public function confirm(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        return $this->update(['status' => 'confirmed']);
    }

    public function markInProgress(): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        return $this->update(['status' => 'in_progress']);
    }

    public function complete(): bool
    {
        if (!in_array($this->status, ['confirmed', 'in_progress'])) {
            return false;
        }

        return $this->update(['status' => 'completed']);
    }

    public function cancel(string $reason = null, $cancelledBy = null): bool
    {
        if ($this->status === 'cancelled') {
            return false;
        }

        return $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'cancelled_by' => $cancelledBy,
        ]);
    }

    public function reschedule(Carbon $newDateTime): bool
    {
        if (!$this->canBeRescheduled) {
            return false;
        }

        return $this->update(['scheduled_at' => $newDateTime]);
    }

    /**
     * Payment Methods
     */
    public function getTotalPaidAmount(): float
    {
        return $this->payments()
            ->where('status', 'completed')
            ->sum('amount');
    }

    public function getRemainingAmount(): float
    {
        return $this->total_amount - $this->getTotalPaidAmount();
    }

    public function isFullyPaid(): bool
    {
        return $this->getRemainingAmount() <= 0;
    }

    public function requiresDeposit(): bool
    {
        return $this->deposit_amount > 0 && $this->getTotalPaidAmount() < $this->deposit_amount;
    }
}
