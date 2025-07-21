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

    /**
     * Get comprehensive user analytics.
     */
    public function getUserAnalytics(string $userId, array $dateRange = []): array
    {
        $startDate = $dateRange['start'] ?? now()->subDays(30)->toDateString();
        $endDate = $dateRange['end'] ?? now()->toDateString();
        
        return Cache::remember(
            "user_analytics_{$userId}_{$startDate}_{$endDate}",
            now()->addMinutes(30),
            function () use ($userId, $startDate, $endDate) {
                $services = Service::where('user_id', $userId)->get();
                
                // Overall metrics
                $totalViews = $services->sum('view_count');
                $totalBookings = $services->sum('booking_count');
                $totalRevenue = $this->calculateTotalRevenue($services, $startDate, $endDate);
                $avgRating = $services->where('review_count', '>', 0)->avg('average_rating') ?: 0;
                
                // Trending data for charts
                $viewsTrend = $this->getViewsTrendData($services, $startDate, $endDate);
                $bookingsTrend = $this->getBookingsTrendData($services, $startDate, $endDate);
                $revenueTrend = $this->getRevenueTrendData($services, $startDate, $endDate);
                
                // Service performance
                $topServices = $this->getTopPerformingServices($services);
                $serviceBreakdown = $this->getServiceBreakdown($services);
                
                return [
                    'overview' => [
                        'total_services' => $services->count(),
                        'active_services' => $services->where('status', 'active')->count(),
                        'total_views' => $totalViews,
                        'total_bookings' => $totalBookings,
                        'total_revenue' => $totalRevenue,
                        'average_rating' => round($avgRating, 1),
                        'conversion_rate' => $totalViews > 0 ? round(($totalBookings / $totalViews) * 100, 2) : 0,
                    ],
                    'trends' => [
                        'views' => $viewsTrend,
                        'bookings' => $bookingsTrend,
                        'revenue' => $revenueTrend,
                    ],
                    'services' => [
                        'top_performing' => $topServices,
                        'breakdown' => $serviceBreakdown,
                    ],
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                ];
            }
        );
    }

    // Analytics helper methods
    private function getMonthlyViews(Service $service): int
    {
        // Mock data - in real app, query from analytics/tracking table
        return rand(50, 500);
    }

    private function getViewsTrend(Service $service): array
    {
        // Mock trend data for last 30 days
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $trend[] = [
                'date' => $date,
                'views' => rand(5, 50),
            ];
        }
        return $trend;
    }

    private function getMonthlyBookings(Service $service): int
    {
        // Mock data - in real app, query from bookings table
        return rand(5, 25);
    }

    private function getRevenue(Service $service): float
    {
        // Mock data - in real app, calculate from completed bookings
        return round(rand(500, 5000) / 100, 2) * 100;
    }

    private function getRatingDistribution(Service $service): array
    {
        // Mock rating distribution
        return [
            '5' => rand(40, 60),
            '4' => rand(20, 30),
            '3' => rand(5, 15),
            '2' => rand(2, 8),
            '1' => rand(0, 5),
        ];
    }

    private function calculateTotalRevenue(Collection $services, string $startDate, string $endDate): float
    {
        // Mock total revenue calculation
        return $services->sum(fn($service) => rand(100, 1000)) / 10;
    }

    private function getViewsTrendData(Collection $services, string $startDate, string $endDate): array
    {
        $trend = [];
        $currentDate = \Carbon\Carbon::parse($startDate);
        $endDateCarbon = \Carbon\Carbon::parse($endDate);
        
        while ($currentDate->lte($endDateCarbon)) {
            $trend[] = [
                'date' => $currentDate->format('Y-m-d'),
                'value' => rand(20, 200),
                'label' => $currentDate->format('M j'),
            ];
            $currentDate->addDay();
        }
        
        return $trend;
    }

    private function getBookingsTrendData(Collection $services, string $startDate, string $endDate): array
    {
        $trend = [];
        $currentDate = \Carbon\Carbon::parse($startDate);
        $endDateCarbon = \Carbon\Carbon::parse($endDate);
        
        while ($currentDate->lte($endDateCarbon)) {
            $trend[] = [
                'date' => $currentDate->format('Y-m-d'),
                'value' => rand(1, 15),
                'label' => $currentDate->format('M j'),
            ];
            $currentDate->addDay();
        }
        
        return $trend;
    }

    private function getRevenueTrendData(Collection $services, string $startDate, string $endDate): array
    {
        $trend = [];
        $currentDate = \Carbon\Carbon::parse($startDate);
        $endDateCarbon = \Carbon\Carbon::parse($endDate);
        
        while ($currentDate->lte($endDateCarbon)) {
            $trend[] = [
                'date' => $currentDate->format('Y-m-d'),
                'value' => rand(50, 500),
                'label' => $currentDate->format('M j'),
            ];
            $currentDate->addDay();
        }
        
        return $trend;
    }

    private function getTopPerformingServices(Collection $services): array
    {
        return $services
            ->sortByDesc('booking_count')
            ->take(5)
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'title' => $service->title,
                    'bookings' => $service->booking_count,
                    'views' => $service->view_count,
                    'revenue' => rand(100, 2000),
                    'rating' => $service->average_rating,
                    'conversion_rate' => $service->view_count > 0 ? 
                        round(($service->booking_count / $service->view_count) * 100, 2) : 0,
                ];
            })
            ->values()
            ->toArray();
    }

    private function getServiceBreakdown(Collection $services): array
    {
        $statusBreakdown = $services->groupBy('status')->map->count();
        $categoryBreakdown = $services->groupBy('category.name')->map->count();
        
        return [
            'by_status' => $statusBreakdown->toArray(),
            'by_category' => $categoryBreakdown->toArray(),
        ];
    }

    /**
     * Get comprehensive earnings data for a user.
     */
    public function getUserEarnings(string $userId, array $dateRange = []): array
    {
        $startDate = $dateRange['start'] ?? now()->subDays(30)->toDateString();
        $endDate = $dateRange['end'] ?? now()->toDateString();
        
        return Cache::remember(
            "user_earnings_{$userId}_{$startDate}_{$endDate}",
            now()->addMinutes(15),
            function () use ($userId, $startDate, $endDate) {
                $services = Service::where('user_id', $userId)->get();
                
                // Calculate earnings metrics
                $totalEarnings = $this->calculateTotalEarnings($services, $startDate, $endDate);
                $availableBalance = $this->calculateAvailableBalance($userId);
                $pendingPayouts = $this->calculatePendingPayouts($userId);
                $totalPayouts = $this->calculateTotalPayouts($userId);
                
                // Transaction history
                $recentTransactions = $this->getRecentTransactions($userId, 10);
                $earningsTrend = $this->getEarningsTrendData($services, $startDate, $endDate);
                
                // Service earnings breakdown
                $serviceEarnings = $this->getServiceEarningsBreakdown($services, $startDate, $endDate);
                $monthlyBreakdown = $this->getMonthlyEarningsBreakdown($userId, $startDate, $endDate);
                
                return [
                    'overview' => [
                        'total_earnings' => $totalEarnings,
                        'available_balance' => $availableBalance,
                        'pending_payouts' => $pendingPayouts,
                        'total_payouts' => $totalPayouts,
                        'earnings_this_month' => $this->getEarningsThisMonth($userId),
                        'growth_percentage' => $this->calculateEarningsGrowth($userId),
                        'average_order_value' => $this->calculateAverageOrderValue($services),
                        'completion_rate' => $this->calculateCompletionRate($userId),
                    ],
                    'trends' => [
                        'earnings' => $earningsTrend,
                        'monthly' => $monthlyBreakdown,
                    ],
                    'transactions' => [
                        'recent' => $recentTransactions,
                        'total_count' => $this->getTotalTransactionCount($userId),
                    ],
                    'services' => [
                        'earnings_breakdown' => $serviceEarnings,
                        'top_earning' => $this->getTopEarningServices($services),
                    ],
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                ];
            }
        );
    }

    /**
     * Process a payout request.
     */
    public function requestPayout(string $userId, float $amount, array $payoutDetails = []): array
    {
        $availableBalance = $this->calculateAvailableBalance($userId);
        
        if ($amount > $availableBalance) {
            throw new \Exception('Insufficient balance for payout request');
        }
        
        if ($amount < 10) { // Minimum payout amount
            throw new \Exception('Minimum payout amount is R10');
        }
        
        // In a real implementation, this would create a payout record
        // and integrate with payment processors like PayFast, Stripe, etc.
        
        return [
            'success' => true,
            'payout_id' => 'payout_' . uniqid(),
            'amount' => $amount,
            'processing_fee' => round($amount * 0.025, 2), // 2.5% fee
            'net_amount' => round($amount * 0.975, 2),
            'estimated_arrival' => now()->addBusinessDays(3)->toDateString(),
            'status' => 'processing',
        ];
    }

    // Earnings calculation helper methods
    private function calculateTotalEarnings(Collection $services, string $startDate, string $endDate): float
    {
        // Mock calculation - in real app, sum from completed bookings
        return $services->sum(function ($service) {
            return $service->booking_count * $service->base_price * 0.85; // 15% platform fee
        });
    }

    private function calculateAvailableBalance(string $userId): float
    {
        // Mock available balance - in real app, calculate from settled transactions
        return rand(1000, 10000) / 10;
    }

    private function calculatePendingPayouts(string $userId): float
    {
        // Mock pending payouts
        return rand(200, 2000) / 10;
    }

    private function calculateTotalPayouts(string $userId): float
    {
        // Mock total payouts - in real app, sum from payout history
        return rand(5000, 50000) / 10;
    }

    private function getEarningsThisMonth(string $userId): float
    {
        // Mock this month's earnings
        return rand(500, 5000) / 10;
    }

    private function calculateEarningsGrowth(string $userId): float
    {
        // Mock growth percentage
        return rand(-20, 50) / 10;
    }

    private function calculateAverageOrderValue(Collection $services): float
    {
        if ($services->isEmpty()) return 0;
        return $services->avg('base_price');
    }

    private function calculateCompletionRate(string $userId): float
    {
        // Mock completion rate
        return rand(85, 98) / 100;
    }

    private function getRecentTransactions(string $userId, int $limit = 10): array
    {
        $transactions = [];
        for ($i = 0; $i < $limit; $i++) {
            $transactions[] = [
                'id' => 'txn_' . uniqid(),
                'type' => rand(0, 1) ? 'earning' : 'payout',
                'amount' => rand(50, 500),
                'description' => $this->getRandomTransactionDescription(),
                'status' => ['completed', 'pending', 'processing'][rand(0, 2)],
                'date' => now()->subDays(rand(0, 30))->toDateString(),
                'service_name' => 'Service ' . rand(1, 5),
            ];
        }
        return $transactions;
    }

    private function getEarningsTrendData(Collection $services, string $startDate, string $endDate): array
    {
        $trend = [];
        $currentDate = \Carbon\Carbon::parse($startDate);
        $endDateCarbon = \Carbon\Carbon::parse($endDate);
        
        while ($currentDate->lte($endDateCarbon)) {
            $trend[] = [
                'date' => $currentDate->format('Y-m-d'),
                'value' => rand(100, 1000),
                'label' => $currentDate->format('M j'),
            ];
            $currentDate->addDay();
        }
        
        return $trend;
    }

    private function getServiceEarningsBreakdown(Collection $services, string $startDate, string $endDate): array
    {
        return $services->map(function ($service) {
            return [
                'service_id' => $service->id,
                'service_name' => $service->title,
                'bookings' => $service->booking_count,
                'gross_earnings' => $service->booking_count * $service->base_price,
                'net_earnings' => $service->booking_count * $service->base_price * 0.85,
                'commission' => $service->booking_count * $service->base_price * 0.15,
            ];
        })->sortByDesc('net_earnings')->take(10)->values()->toArray();
    }

    private function getMonthlyEarningsBreakdown(string $userId, string $startDate, string $endDate): array
    {
        $breakdown = [];
        $currentDate = \Carbon\Carbon::parse($startDate)->startOfMonth();
        $endDateCarbon = \Carbon\Carbon::parse($endDate);
        
        while ($currentDate->lte($endDateCarbon)) {
            $breakdown[] = [
                'month' => $currentDate->format('Y-m'),
                'label' => $currentDate->format('M Y'),
                'earnings' => rand(1000, 8000),
                'bookings' => rand(10, 50),
                'commission' => rand(150, 1200),
            ];
            $currentDate->addMonth();
        }
        
        return $breakdown;
    }

    private function getTopEarningServices(Collection $services): array
    {
        return $services
            ->sortByDesc(function ($service) {
                return $service->booking_count * $service->base_price;
            })
            ->take(5)
            ->map(function ($service) {
                $grossEarnings = $service->booking_count * $service->base_price;
                return [
                    'id' => $service->id,
                    'title' => $service->title,
                    'bookings' => $service->booking_count,
                    'gross_earnings' => $grossEarnings,
                    'net_earnings' => $grossEarnings * 0.85,
                    'commission_rate' => 15,
                ];
            })
            ->values()
            ->toArray();
    }

    private function getTotalTransactionCount(string $userId): int
    {
        // Mock total transaction count
        return rand(50, 500);
    }

    private function getRandomTransactionDescription(): string
    {
        $descriptions = [
            'Payment for Hair Styling Service',
            'Payout to Bank Account',
            'Booking Payment Received',
            'Weekly Earnings Transfer',
            'Service Completion Payment',
            'Customer Tip Received',
            'Refund Processed',
            'Bonus Payment',
        ];
        
        return $descriptions[array_rand($descriptions)];
    }

    /**
     * Get available slots for a service within a date range.
     */
    public function getAvailableSlots(Service $service, string $startDate, string $endDate, int $duration): array
    {
        // For now, return mock data. In a real implementation, this would:
        // 1. Get the vendor's availability for the date range
        // 2. Generate time slots based on working hours
        // 3. Filter out already booked slots
        // 4. Consider service duration and buffer times
        
        $availableSlots = [];
        $currentDate = \Carbon\Carbon::parse($startDate);
        $endDateCarbon = \Carbon\Carbon::parse($endDate);
        
        // Mock availability: 9 AM to 5 PM, Monday to Friday
        while ($currentDate->lte($endDateCarbon)) {
            // Skip weekends for now
            if ($currentDate->isWeekday()) {
                $daySlots = [];
                
                // Generate slots from 9 AM to 5 PM with 1-hour intervals
                for ($hour = 9; $hour < 17; $hour++) {
                    $slotTime = sprintf('%02d:00', $hour);
                    $slotDateTime = $currentDate->format('Y-m-d') . 'T' . $slotTime . ':00';
                    
                    $daySlots[] = [
                        'id' => uniqid(),
                        'date' => $currentDate->format('Y-m-d'),
                        'time' => $slotTime,
                        'datetime' => $slotDateTime,
                        'available' => true,
                        'duration_minutes' => $duration
                    ];
                }
                
                if (!empty($daySlots)) {
                    $availableSlots[] = [
                        'date' => $currentDate->format('Y-m-d'),
                        'day_name' => $currentDate->format('l'),
                        'formatted_date' => $currentDate->format('M j'),
                        'slots' => $daySlots,
                        'has_slots' => true
                    ];
                }
            }
            
            $currentDate->addDay();
        }
        
        return $availableSlots;
    }
}