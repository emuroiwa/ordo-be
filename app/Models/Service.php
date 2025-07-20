<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Service extends Model
{
    use HasFactory, HasUuids, SoftDeletes, Searchable;

    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'description',
        'short_description',
        'price_type',
        'base_price',
        'max_price',
        'currency',
        'duration_minutes',
        'location_type',
        'address',
        'latitude',
        'longitude',
        'images',
        'tags',
        'requirements',
        'status',
        'is_featured',
        'instant_booking',
        'meta_title',
        'meta_description',
        'slug',
        'availability_schedule',
        'advance_booking_days',
        'cancellation_hours',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'address' => 'array',
        'images' => 'array',
        'tags' => 'array',
        'requirements' => 'array',
        'availability_schedule' => 'array',
        'is_featured' => 'boolean',
        'instant_booking' => 'boolean',
        'average_rating' => 'decimal:2',
        'duration_minutes' => 'integer',
        'advance_booking_days' => 'integer',
        'cancellation_hours' => 'integer',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    protected $appends = [
        'formatted_price',
        'primary_image',
        'location_display',
        'full_slug',
    ];

    /**
     * Get the user that owns the service.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get bookings for this service.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get availability slots for this service.
     */
    public function availabilitySlots(): HasMany
    {
        return $this->hasMany(AvailabilitySlot::class);
    }

    /**
     * Get reviews for this service.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(BookingReview::class);
    }

    /**
     * Get the category for this service.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * Get the service images.
     */
    public function serviceImages(): HasMany
    {
        return $this->hasMany(ServiceImage::class)->orderBy('sort_order');
    }

    /**
     * Get the primary image.
     */
    public function primaryImage(): HasMany
    {
        return $this->serviceImages()->where('is_primary', true);
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory(Builder $query, string $categorySlug): Builder
    {
        return $query->whereHas('category', function ($q) use ($categorySlug) {
            $q->where('slug', $categorySlug);
        });
    }

    public function scopeInPriceRange(Builder $query, float $min, float $max): Builder
    {
        return $query->whereBetween('base_price', [$min, $max]);
    }

    public function scopeWithinRadius(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        // Use Haversine formula for distance calculation without spatial functions
        $earthRadius = 6371; // Earth's radius in kilometers
        
        return $query->whereRaw(
            "({$earthRadius} * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
            [$lat, $lng, $lat, $radiusKm]
        );
    }

    public function scopeMinRating(Builder $query, float $rating): Builder
    {
        return $query->where('average_rating', '>=', $rating);
    }

    // Accessors
    public function getFormattedPriceAttribute(): string
    {
        $price = number_format($this->base_price, 2);
        
        if ($this->max_price && $this->max_price > $this->base_price) {
            $maxPrice = number_format($this->max_price, 2);
            return "R{$price} - R{$maxPrice}";
        }
        
        return "R{$price}";
    }

    public function getPrimaryImageAttribute(): ?array
    {
        $primaryImage = $this->serviceImages()->where('is_primary', true)->first();
        
        if ($primaryImage) {
            return [
                'id' => $primaryImage->id,
                'url' => $primaryImage->cdn_url ?: asset('storage/' . $primaryImage->original_path),
                'webp_url' => $primaryImage->webp_path ? asset('storage/' . $primaryImage->webp_path) : null,
                'alt' => $primaryImage->alt_text ?: $this->title,
                'blurhash' => $primaryImage->blurhash,
            ];
        }

        return null;
    }

    public function getLocationDisplayAttribute(): string
    {
        if ($this->location_type === 'online') {
            return 'Online Service';
        }

        if ($this->address && isset($this->address['city'])) {
            return $this->address['city'] . ', ' . ($this->address['province'] ?? 'South Africa');
        }

        return ucfirst($this->location_type) . ' Service';
    }

    public function getFullSlugAttribute(): string
    {
        if ($this->user && $this->user->slug) {
            return $this->user->slug . '/' . $this->slug;
        }
        
        return $this->slug ?? '';
    }

    // Scout search configuration
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'tags' => $this->tags,
            'category' => $this->category->name ?? null,
            'location_type' => $this->location_type,
            'price' => $this->base_price,
            'rating' => $this->average_rating,
            'status' => $this->status,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status === 'active';
    }

    // Helper methods
    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function updateRating(float $newRating): void
    {
        $this->increment('review_count');
        
        // Recalculate average rating
        $totalRating = ($this->average_rating * ($this->review_count - 1)) + $newRating;
        $this->average_rating = $totalRating / $this->review_count;
        $this->save();
    }

    public function isBookableBy(User $user): bool
    {
        return $this->status === 'active' && 
               $this->user_id !== $user->id;
    }

    public function getAvailabilityForDate(string $date): array
    {
        // Implementation for checking availability on a specific date
        // This would integrate with booking system
        return [];
    }

    /**
     * Find service by full slug (user-slug/service-slug).
     */
    public static function findByFullSlug(string $fullSlug): ?self
    {
        $parts = explode('/', $fullSlug);
        
        if (count($parts) !== 2) {
            return null;
        }

        [$userSlug, $serviceSlug] = $parts;

        return static::whereHas('user', function ($query) use ($userSlug) {
            $query->where('slug', $userSlug);
        })->where('slug', $serviceSlug)->first();
    }
}