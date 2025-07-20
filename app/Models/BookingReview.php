<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'vendor_id',
        'service_id',
        'rating',
        'review_text',
        'is_published',
        'review_metadata',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'review_metadata' => 'array',
        'rating' => 'integer',
    ];

    /**
     * Relationships
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

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

    /**
     * Scopes
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForVendor($query, string $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForService($query, string $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeByRating($query, int $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeHighRated($query, int $minRating = 4)
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Accessors
     */
    public function getStarRatingAttribute(): string
    {
        return str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
    }

    public function getIsPositiveAttribute(): bool
    {
        return $this->rating >= 4;
    }

    public function getIsNegativeAttribute(): bool
    {
        return $this->rating <= 2;
    }

    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('M j, Y');
    }

    /**
     * Business Logic Methods
     */
    public function publish(): bool
    {
        return $this->update(['is_published' => true]);
    }

    public function unpublish(): bool
    {
        return $this->update(['is_published' => false]);
    }

    /**
     * Static Methods
     */
    public static function getAverageRatingForVendor(string $vendorId): float
    {
        return static::published()
            ->forVendor($vendorId)
            ->avg('rating') ?? 0;
    }

    public static function getAverageRatingForService(string $serviceId): float
    {
        return static::published()
            ->forService($serviceId)
            ->avg('rating') ?? 0;
    }

    public static function getReviewCountForVendor(string $vendorId): int
    {
        return static::published()
            ->forVendor($vendorId)
            ->count();
    }

    public static function getReviewCountForService(string $serviceId): int
    {
        return static::published()
            ->forService($serviceId)
            ->count();
    }
}
