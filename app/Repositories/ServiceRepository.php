<?php

namespace App\Repositories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ServiceRepository
{
    /**
     * Get services with filters and pagination.
     */
    public function getServicesWithFilters(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $query = Service::with(['category', 'user:id,name', 'serviceImages' => function ($q) {
            $q->where('is_primary', true)->processed();
        }])
        ->active()
        ->select([
            'id', 'user_id', 'category_id', 'title', 'short_description',
            'price_type', 'base_price', 'max_price', 'currency',
            'location_type', 'latitude', 'longitude', 'is_featured',
            'average_rating', 'review_count', 'created_at'
        ]);

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get services for a specific user.
     */
    public function getUserServices(string $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Service::with(['category', 'serviceImages' => function ($q) {
            $q->where('is_primary', true);
        }])
        ->where('user_id', $userId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Create a new service.
     */
    public function create(array $data): Service
    {
        return Service::create($data);
    }

    /**
     * Update a service.
     */
    public function update(Service $service, array $data): bool
    {
        return $service->update($data);
    }

    /**
     * Delete a service.
     */
    public function delete(Service $service): bool
    {
        return $service->delete();
    }

    /**
     * Find service by ID.
     */
    public function findById(string $id): ?Service
    {
        return Service::with([
            'category',
            'user:id,name,email,avatar_url,business_name,slug,created_at',
            'serviceImages' => function ($q) {
                $q->processed()->ordered();
            }
        ])->find($id);
    }

    /**
     * Find service by user and service slugs.
     */
    public function findBySlug(string $userSlug, string $serviceSlug): ?Service
    {
        return Service::with([
            'category',
            'user:id,name,email,business_name,slug,created_at',
            'serviceImages' => function ($q) {
                $q->processed()->ordered();
            }
        ])
        ->whereHas('user', function ($query) use ($userSlug) {
            $query->where('slug', $userSlug);
        })
        ->where('slug', $serviceSlug)
        ->first();
    }

    /**
     * Search services using full-text search.
     */
    public function search(string $query, int $perPage = 12): LengthAwarePaginator
    {
        return Service::search($query)
            ->where('status', 'active')
            ->paginate($perPage);
    }

    /**
     * Check if slug exists for a service.
     */
    public function slugExists(string $slug, ?string $excludeId = null): bool
    {
        $query = Service::where('slug', $slug);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }

    /**
     * Get all active service categories.
     */
    public function getCategories(): Collection
    {
        return ServiceCategory::active()->ordered()->get();
    }

    /**
     * Apply filters to the query.
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Category filter
        if (!empty($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        // Price range filter
        if (!empty($filters['price_min']) || !empty($filters['price_max'])) {
            $min = $filters['price_min'] ?? 0;
            $max = $filters['price_max'] ?? 999999;
            $query->inPriceRange($min, $max);
        }

        // Location filter
        if (!empty($filters['latitude']) && !empty($filters['longitude'])) {
            $lat = $filters['latitude'];
            $lng = $filters['longitude'];
            $radius = $filters['radius'] ?? 25; // Default 25km
            
            $query->withinRadius($lat, $lng, $radius);
        }

        // Rating filter
        if (!empty($filters['min_rating'])) {
            $query->minRating($filters['min_rating']);
        }

        // Location type filter
        if (!empty($filters['location_type'])) {
            $query->where('location_type', $filters['location_type']);
        }

        // Featured filter
        if (!empty($filters['featured'])) {
            $query->featured();
        }

        // Instant booking filter
        if (!empty($filters['instant_booking'])) {
            $query->where('instant_booking', true);
        }

        // Tags filter
        if (!empty($filters['tags'])) {
            $tags = is_array($filters['tags']) ? $filters['tags'] : explode(',', $filters['tags']);
            $query->whereJsonContains('tags', $tags);
        }
    }

    /**
     * Apply sorting to the query.
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sort = $filters['sort'] ?? 'relevance';

        match ($sort) {
            'price_low' => $query->orderBy('base_price', 'asc'),
            'price_high' => $query->orderBy('base_price', 'desc'),
            'rating' => $query->orderByDesc('average_rating')->orderByDesc('review_count'),
            'newest' => $query->orderByDesc('created_at'),
            'popular' => $query->orderByDesc('booking_count')->orderByDesc('view_count'),
            'distance' => $this->applySpatialSorting($query, $filters),
            default => $query->orderByDesc('is_featured')->orderByDesc('average_rating'),
        };
    }

    /**
     * Apply spatial sorting for distance-based queries.
     */
    private function applySpatialSorting(Builder $query, array $filters): void
    {
        if (!empty($filters['latitude']) && !empty($filters['longitude'])) {
            $lat = $filters['latitude'];
            $lng = $filters['longitude'];
            $earthRadius = 6371; // Earth's radius in kilometers
            
            $query->orderByRaw(
                "({$earthRadius} * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) ASC",
                [$lat, $lng, $lat]
            );
        } else {
            // Fallback to default sorting
            $query->orderByDesc('is_featured')->orderByDesc('average_rating');
        }
    }
}