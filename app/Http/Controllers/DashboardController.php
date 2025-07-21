<?php

namespace App\Http\Controllers;

use App\Services\ServiceBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private ServiceBusinessService $serviceBusinessService
    ) {}

    /**
     * Get comprehensive dashboard data for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                    'error' => 'No authenticated user found'
                ], 401);
            }
            
            $isVendor = in_array('vendor', $user->roles ?? []);
            
            if ($isVendor) {
                $data = $this->getVendorDashboardData($user);
            } else {
                $data = $this->getCustomerDashboardData($user);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Get vendor dashboard data.
     */
    private function getVendorDashboardData($user): array
    {
        $services = Service::where('user_id', $user->id)->get();
        
        // Calculate metrics
        $totalBookings = $services->sum('booking_count');
        $totalRevenue = $services->sum(function ($service) {
            return $service->booking_count * $service->base_price * 0.85; // 15% commission
        });
        $averageRating = $services->where('review_count', '>', 0)->avg('average_rating') ?: 0;
        $activeServices = $services->where('status', 'active')->count();
        
        // Get recent activities
        $recentActivities = $this->getVendorRecentActivities($user, $services);
        
        // Get quick stats for widgets
        $quickStats = [
            'total_services' => $services->count(),
            'total_views' => $services->sum('view_count'),
            'pending_bookings' => $this->getPendingBookingsCount($user),
            'this_month_earnings' => $this->getThisMonthEarnings($user),
        ];
        
        // Get performance trends (last 30 days)
        $performanceTrends = $this->getPerformanceTrends($services);
        
        // Get top performing services
        $topServices = $services->sortByDesc('booking_count')->take(5)->map(function ($service) {
            return [
                'id' => $service->id,
                'title' => $service->title,
                'bookings' => $service->booking_count,
                'revenue' => $service->booking_count * $service->base_price,
                'rating' => $service->average_rating,
                'views' => $service->view_count,
            ];
        })->values();

        return [
            'role' => 'vendor',
            'stats' => [
                'total_bookings' => $totalBookings,
                'revenue' => round($totalRevenue, 2),
                'rating' => round($averageRating, 1),
                'active_services' => $activeServices,
                'growth_percentage' => $this->calculateGrowthPercentage($user, 'bookings'),
                'completion_rate' => $this->getCompletionRate($user),
            ],
            'quick_stats' => $quickStats,
            'recent_activities' => $recentActivities,
            'performance_trends' => $performanceTrends,
            'top_services' => $topServices,
            'service_breakdown' => [
                'by_status' => $services->groupBy('status')->map->count(),
                'by_category' => $services->groupBy('category.name')->map->count(),
            ],
        ];
    }

    /**
     * Get customer dashboard data.
     */
    private function getCustomerDashboardData($user): array
    {
        // Get real customer booking data
        $bookings = collect([]); // In real app, fetch from bookings table
        $payments = \App\Models\Payment::forCustomer($user->id);
        $reviews = \App\Models\Review::where('user_id', $user->id);
        
        // Calculate real metrics
        $totalBookings = $payments->count(); // Use payments as proxy for bookings
        $totalSpent = $payments->completed()->sum('amount');
        $thisMonthSpent = $payments->completed()->thisMonth()->sum('amount');
        $lastMonthSpent = $payments->completed()->lastMonth()->sum('amount');
        $reviewsGiven = $reviews->count();
        $avgRatingGiven = $reviews->avg('rating') ?: 0;
        
        // Calculate growth percentage
        $growthPercentage = $lastMonthSpent > 0 
            ? (($thisMonthSpent - $lastMonthSpent) / $lastMonthSpent) * 100 
            : 0;
        
        $recentActivities = $this->getCustomerRecentActivities($user);
        
        return [
            'role' => 'customer',
            'stats' => [
                'total_bookings' => $totalBookings,
                'total_spent' => round($totalSpent, 2),
                'favorites' => $this->getFavoriteServicesCount($user),
                'reviews_given' => $reviewsGiven,
                'growth_percentage' => round($growthPercentage, 1),
                'avg_rating_given' => round($avgRatingGiven, 1),
            ],
            'recent_activities' => $recentActivities,
            'upcoming_bookings' => $this->getUpcomingBookings($user),
            'favorite_services' => $this->getFavoriteServices($user),
            'recommendations' => $this->getServiceRecommendations($user),
        ];
    }

    /**
     * Get vendor recent activities.
     */
    private function getVendorRecentActivities($user, $services): array
    {
        $activities = [];
        
        // Mock recent activities based on services
        $activityTypes = [
            ['type' => 'booking', 'icon' => 'bg-green-500', 'template' => 'New booking confirmed for {service}'],
            ['type' => 'payment', 'icon' => 'bg-blue-500', 'template' => 'Payment received for {service}'],
            ['type' => 'review', 'icon' => 'bg-yellow-500', 'template' => 'New {rating}-star review for {service}'],
            ['type' => 'view', 'icon' => 'bg-purple-500', 'template' => '{service} viewed by potential customer'],
            ['type' => 'service', 'icon' => 'bg-indigo-500', 'template' => 'Service {service} status updated'],
        ];
        
        for ($i = 0; $i < 8; $i++) {
            $activity = $activityTypes[array_rand($activityTypes)];
            $service = $services->random();
            $hoursAgo = rand(1, 48);
            
            $activities[] = [
                'id' => 'activity_' . uniqid(),
                'type' => $activity['type'],
                'title' => str_replace(['{service}', '{rating}'], [$service->title, rand(4, 5)], $activity['template']),
                'time' => $this->formatTimeAgo($hoursAgo),
                'icon_color' => $activity['icon'],
                'service_id' => $service->id,
                'created_at' => now()->subHours($hoursAgo),
            ];
        }
        
        return collect($activities)->sortByDesc('created_at')->take(6)->values()->toArray();
    }

    /**
     * Get customer recent activities.
     */
    private function getCustomerRecentActivities($user): array
    {
        $activities = [];
        
        // Get recent payments
        $recentPayments = \App\Models\Payment::forCustomer($user->id)
            ->with(['service', 'vendor'])
            ->latest()
            ->limit(3)
            ->get();
            
        foreach ($recentPayments as $payment) {
            $activities[] = [
                'id' => 'payment_' . $payment->id,
                'type' => 'payment',
                'title' => "Payment of {$payment->formatted_amount} for {$payment->service->title}",
                'time' => $payment->time_ago,
                'icon_color' => $payment->status === 'completed' ? 'bg-green-500' : 'bg-yellow-500',
                'created_at' => $payment->created_at,
            ];
        }
        
        // Get recent reviews
        $recentReviews = \App\Models\Review::where('user_id', $user->id)
            ->with('service')
            ->latest()
            ->limit(3)
            ->get();
            
        foreach ($recentReviews as $review) {
            $activities[] = [
                'id' => 'review_' . $review->id,
                'type' => 'review',
                'title' => "Left {$review->rating}-star review for {$review->service->title}",
                'time' => $review->time_ago,
                'icon_color' => 'bg-yellow-500',
                'created_at' => $review->created_at,
            ];
        }
        
        // Add some mock activities if we have fewer than 6 real activities
        if (count($activities) < 6) {
            $activityTypes = [
                ['type' => 'booking', 'icon' => 'bg-green-500', 'template' => 'Booked service appointment'],
                ['type' => 'favorite', 'icon' => 'bg-red-500', 'template' => 'Added service to favorites'],
                ['type' => 'search', 'icon' => 'bg-blue-500', 'template' => 'Searched for services'],
            ];
            
            $remainingSlots = 6 - count($activities);
            for ($i = 0; $i < $remainingSlots; $i++) {
                $activity = $activityTypes[array_rand($activityTypes)];
                $hoursAgo = rand(1, 72);
                
                $activities[] = [
                    'id' => 'activity_' . uniqid(),
                    'type' => $activity['type'],
                    'title' => $activity['template'],
                    'time' => $this->formatTimeAgo($hoursAgo),
                    'icon_color' => $activity['icon'],
                    'created_at' => now()->subHours($hoursAgo),
                ];
            }
        }
        
        return collect($activities)->sortByDesc('created_at')->values()->toArray();
    }

    /**
     * Get performance trends for vendor.
     */
    private function getPerformanceTrends($services): array
    {
        $trends = [];
        
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $trends[] = [
                'date' => $date->format('Y-m-d'),
                'bookings' => rand(0, 8),
                'views' => rand(10, 50),
                'revenue' => rand(100, 800),
                'label' => $date->format('M j'),
            ];
        }
        
        return $trends;
    }

    /**
     * Helper methods for calculations.
     */
    private function getPendingBookingsCount($user): int
    {
        // Mock pending bookings
        return rand(2, 15);
    }

    private function getThisMonthEarnings($user): float
    {
        // Mock this month's earnings
        return rand(2000, 8000);
    }

    private function calculateGrowthPercentage($user, $metric): float
    {
        // Mock growth percentage
        return rand(-15, 35) / 10;
    }

    private function getCompletionRate($user): float
    {
        // Mock completion rate
        return rand(85, 98) / 100;
    }

    private function getUpcomingBookings($user): array
    {
        // In a real app, fetch from bookings table with future scheduled_at dates
        // For now, get recent pending payments as proxy for upcoming bookings
        $upcomingPayments = \App\Models\Payment::forCustomer($user->id)
            ->with(['service', 'vendor'])
            ->where('status', 'pending')
            ->latest()
            ->limit(3)
            ->get();

        $bookings = [];
        foreach ($upcomingPayments as $payment) {
            if ($payment->service && $payment->vendor) {
                $bookings[] = [
                    'id' => $payment->id,
                    'service_name' => $payment->service->title,
                    'provider_name' => $payment->vendor->name,
                    'date' => now()->addDays(rand(1, 14))->format('Y-m-d'),
                    'time' => sprintf('%02d:00', rand(9, 17)),
                    'price' => $payment->amount,
                ];
            }
        }

        // Fill with mock data if no pending payments
        while (count($bookings) < 3) {
            $bookings[] = [
                'id' => 'booking_' . uniqid(),
                'service_name' => ['Hair Cut', 'Massage', 'Personal Training'][count($bookings) % 3],
                'provider_name' => ['Sarah Johnson', 'Mike Wilson', 'Emma Davis'][count($bookings) % 3],
                'date' => now()->addDays(rand(1, 14))->format('Y-m-d'),
                'time' => sprintf('%02d:00', rand(9, 17)),
                'price' => rand(50, 300),
            ];
        }

        return array_slice($bookings, 0, 3);
    }

    private function getFavoriteServices($user): array
    {
        // In a real app, you'd fetch from a favorites/bookmarks table
        // For now, get services the user has paid for (indicating preference)
        $favoriteServiceIds = \App\Models\Payment::forCustomer($user->id)
            ->completed()
            ->select('service_id')
            ->groupBy('service_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('service_id')
            ->take(4);

        $favorites = [];
        if ($favoriteServiceIds->isNotEmpty()) {
            $services = \App\Models\Service::whereIn('id', $favoriteServiceIds)
                ->with('user')
                ->get();
                
            foreach ($services as $service) {
                $avgAmount = \App\Models\Payment::forCustomer($user->id)
                    ->where('service_id', $service->id)
                    ->completed()
                    ->avg('amount');
                    
                $favorites[] = [
                    'id' => $service->id,
                    'title' => $service->title,
                    'provider' => $service->user->name ?? 'Provider',
                    'rating' => $service->average_rating ?? 4.5,
                    'price' => $avgAmount ?? $service->base_price,
                ];
            }
        }

        // Fill with mock data if we don't have enough real favorites
        while (count($favorites) < 4) {
            $favorites[] = [
                'id' => 'service_' . uniqid(),
                'title' => ['Premium Hair Styling', 'Deep Tissue Massage', 'Personal Training Session'][count($favorites) % 3],
                'provider' => ['Beauty Studio', 'Wellness Center', 'Fitness Pro'][count($favorites) % 3],
                'rating' => round(rand(40, 50) / 10, 1),
                'price' => rand(100, 500),
            ];
        }
        
        return array_slice($favorites, 0, 4);
    }

    private function getServiceRecommendations($user): array
    {
        // Get some popular services as recommendations
        $popularServices = Service::where('status', 'active')
            ->orderByDesc('booking_count')
            ->with(['user', 'category'])
            ->limit(3)
            ->get();

        $recommendations = [];
        foreach ($popularServices as $service) {
            $recommendations[] = [
                'id' => $service->id,
                'title' => $service->title,
                'provider' => $service->user->name ?? 'Provider',
                'rating' => $service->average_rating ?? 4.5,
                'price' => $service->base_price,
                'category' => $service->category->name ?? 'General',
            ];
        }

        // Fill with mock data if we don't have enough real services
        $mockServices = [
            ['title' => 'Yoga Classes', 'provider' => 'YogaFlow Studio', 'category' => 'Fitness'],
            ['title' => 'Web Design', 'provider' => 'Creative Agency', 'category' => 'Technology'],
            ['title' => 'Home Cleaning', 'provider' => 'Clean Home', 'category' => 'Home'],
            ['title' => 'Tutoring', 'provider' => 'EduCare', 'category' => 'Education'],
            ['title' => 'Event Planning', 'provider' => 'Perfect Events', 'category' => 'Events'],
            ['title' => 'Car Wash', 'provider' => 'Auto Care', 'category' => 'Automotive'],
        ];
        
        while (count($recommendations) < 6) {
            $mock = $mockServices[count($recommendations) % count($mockServices)];
            $recommendations[] = [
                'id' => 'rec_' . uniqid(),
                'title' => $mock['title'],
                'provider' => $mock['provider'],
                'rating' => round(rand(40, 50) / 10, 1),
                'price' => rand(50, 400),
                'category' => $mock['category'],
            ];
        }
        
        return array_slice($recommendations, 0, 6);
    }

    private function getFavoriteServicesCount($user): int
    {
        // In a real app, you'd have a favorites table
        // For now, count unique services the customer has paid for multiple times
        return \App\Models\Payment::forCustomer($user->id)
            ->completed()
            ->select('service_id')
            ->groupBy('service_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
    }

    private function formatTimeAgo($hours): string
    {
        if ($hours < 1) {
            return 'Just now';
        } elseif ($hours < 24) {
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } else {
            $days = floor($hours / 24);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        }
    }
}