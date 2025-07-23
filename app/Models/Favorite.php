<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'service_id',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
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

    // Scopes
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeWithService($query)
    {
        return $query->with(['service' => function ($q) {
            $q->with(['user', 'category', 'serviceImages' => function ($img) {
                $img->where('is_primary', true);
            }]);
        }]);
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

    // Static methods
    public static function isFavorited($userId, $serviceId): bool
    {
        return static::where('user_id', $userId)
            ->where('service_id', $serviceId)
            ->exists();
    }

    public static function getFavoriteId($userId, $serviceId): ?string
    {
        $favorite = static::where('user_id', $userId)
            ->where('service_id', $serviceId)
            ->first();
            
        return $favorite?->id;
    }

    public static function toggleFavorite($userId, $serviceId): array
    {
        $favorite = static::where('user_id', $userId)
            ->where('service_id', $serviceId)
            ->first();

        if ($favorite) {
            $favorite->delete();
            return [
                'action' => 'removed',
                'favorited' => false,
                'favorite_id' => null
            ];
        } else {
            $newFavorite = static::create([
                'user_id' => $userId,
                'service_id' => $serviceId
            ]);
            
            return [
                'action' => 'added',
                'favorited' => true,
                'favorite_id' => $newFavorite->id
            ];
        }
    }

    public static function getFavoritesCount($userId): int
    {
        return static::where('user_id', $userId)->count();
    }
}