<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'service_id',
        'booking_id',
        'rating',
        'title',
        'comment',
        'vendor_response',
        'vendor_response_at',
        'is_verified',
        'is_featured',
        'helpful_count',
        'status',
        'metadata',
    ];

    protected $casts = [
        'rating' => 'integer',
        'vendor_response_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_featured' => 'boolean',
        'helpful_count' => 'integer',
        'metadata' => 'array',
    ];

    protected $dates = [
        'vendor_response_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    // Scopes
    public function scopeForVendor($query, $vendorId)
    {
        return $query->whereHas('service', function ($q) use ($vendorId) {
            $q->where('user_id', $vendorId);
        });
    }

    public function scopeByRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeWithResponse($query)
    {
        return $query->whereNotNull('vendor_response');
    }

    public function scopeWithoutResponse($query)
    {
        return $query->whereNull('vendor_response');
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Accessors
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at->format('M j, Y');
    }

    public function getTimeAgoAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    public function getHasResponseAttribute()
    {
        return !is_null($this->vendor_response);
    }

    public function getRatingStarsAttribute()
    {
        return str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
    }

    // Static methods
    public static function getAverageRatingForService($serviceId)
    {
        return static::where('service_id', $serviceId)->avg('rating') ?: 0;
    }

    public static function getReviewCountForService($serviceId)
    {
        return static::where('service_id', $serviceId)->count();
    }

    public static function getRatingDistributionForVendor($vendorId)
    {
        return static::forVendor($vendorId)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->pluck('count', 'rating')
            ->toArray();
    }
}