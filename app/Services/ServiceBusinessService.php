<?php

namespace App\Services;

use App\Models\Service;
use App\Repositories\ServiceRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceBusinessService
{
    public function __construct(
        private ServiceRepository $serviceRepository,
        private ImageProcessingService $imageProcessor
    ) {}

    /**
     * Get services with caching and filtering.
     */
    public function getServices(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $cacheKey = $this->generateCacheKey($filters, $perPage);
        
        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($filters, $perPage) {
            return $this->serviceRepository->getServicesWithFilters($filters, $perPage);
        });
    }

    /**
     * Get services for authenticated user.
     */
    public function getUserServices(string $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->serviceRepository->getUserServices($userId, $filters, $perPage);
    }

    /**
     * Create a new service.
     */
    public function createService(array $data, ?array $images = null): Service
    {
        DB::beginTransaction();

        try {
            // Generate unique slug
            $data['slug'] = $this->generateUniqueSlug($data['title']);
            
            // Create service
            $service = $this->serviceRepository->create($data);

            // Process images if provided
            if ($images) {
                $this->processServiceImages($service, $images);
            }

            DB::commit();

            // Clear relevant caches
            // $this->clearServiceCaches();

            return $service;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing service.
     */
    public function updateService(Service $service, array $data, ?array $images = null): Service
    {
        DB::beginTransaction();

        try {
            // Update slug if title changed
            if (isset($data['title']) && $data['title'] !== $service->title) {
                $data['slug'] = $this->generateUniqueSlug($data['title'], $service->id);
            }

            // Update service
            $this->serviceRepository->update($service, $data);

            // Process new images if provided
            if ($images) {
                $this->processServiceImages($service, $images);
            }

            DB::commit();

            // Clear relevant caches
            $this->clearServiceCaches();

            // Refresh the model
            $service->refresh();

            return $service;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete a service.
     */
    public function deleteService(Service $service): void
    {
        // Soft delete the service
        $this->serviceRepository->delete($service);

        // Schedule cleanup of associated images
        dispatch(fn() => $this->imageProcessor->deleteServiceImages($service->id))->afterResponse();

        // Clear caches
        $this->clearServiceCaches();
    }

    /**
     * Find service by ID.
     */
    public function findServiceById(string $id): ?Service
    {
        return $this->serviceRepository->findById($id);
    }

    /**
     * Find service by slugs.
     */
    public function findServiceBySlug(string $userSlug, string $serviceSlug): ?Service
    {
        $service = $this->serviceRepository->findBySlug($userSlug, $serviceSlug);
        
        if ($service) {
            // Increment view count asynchronously
            dispatch(fn() => $service->incrementViewCount())->afterResponse();
        }
        
        return $service;
    }

    /**
     * Search services.
     */
    public function searchServices(string $query, int $perPage = 12): LengthAwarePaginator
    {
        if (empty($query)) {
            return $this->getServices([], $perPage);
        }

        return $this->serviceRepository->search($query, $perPage);
    }

    /**
     * Get all service categories with caching.
     */
    public function getCategories(): Collection
    {
        return Cache::remember('service_categories', now()->addHours(24), function () {
            return $this->serviceRepository->getCategories();
        });
    }

    /**
     * Get service analytics.
     */
    public function getServiceAnalytics(Service $service): array
    {
        return Cache::remember(
            "service_analytics_{$service->id}",
            now()->addHours(1),
            function () use ($service) {
                return [
                    'views' => [
                        'total' => $service->view_count,
                        'this_month' => $this->getMonthlyViews($service),
                        'trend' => $this->getViewsTrend($service),
                    ],
                    'bookings' => [
                        'total' => $service->booking_count,
                        'this_month' => $this->getMonthlyBookings($service),
                        'revenue' => $this->getRevenue($service),
                    ],
                    'ratings' => [
                        'average' => $service->average_rating,
                        'count' => $service->review_count,
                        'distribution' => $this->getRatingDistribution($service),
                    ],
                    'conversion' => [
                        'view_to_booking' => $service->booking_count > 0 ? 
                            round(($service->booking_count / max($service->view_count, 1)) * 100, 2) : 0,
                        'favorites' => $service->favorite_count,
                    ],
                ];
            }
        );
    }

    /**
     * Generate unique slug for service.
     */
    private function generateUniqueSlug(string $title, ?string $excludeId = null): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while ($this->serviceRepository->slugExists($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Process service images.
     */
    private function processServiceImages(Service $service, array $images): void
    {
        foreach ($images as $image) {
            if ($image instanceof UploadedFile) {
                $this->imageProcessor->processServiceImage($image, $service->id);
            }
        }
    }

    /**
     * Generate cache key for filtered results.
     */
    private function generateCacheKey(array $filters, int $perPage): string
    {
        $params = array_merge($filters, ['per_page' => $perPage]);
        return 'services_' . md5(serialize($params));
    }

    /**
     * Clear service-related caches.
     */
    private function clearServiceCaches(): void
    {
        Cache::tags(['services'])->flush();
    }

    // Analytics helper methods (simplified - implement based on your analytics needs)
    private function getMonthlyViews(Service $service): int
    {
        // Implement based on your analytics tracking
        return 0;
    }

    private function getViewsTrend(Service $service): array
    {
        // Return trend data for charts
        return [];
    }

    private function getMonthlyBookings(Service $service): int
    {
        // Implement based on your booking system
        return 0;
    }

    private function getRevenue(Service $service): float
    {
        // Calculate total revenue from bookings
        return 0.0;
    }

    private function getRatingDistribution(Service $service): array
    {
        // Return rating distribution (1-5 stars)
        return [];
    }
}