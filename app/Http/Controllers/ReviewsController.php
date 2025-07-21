<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReviewsController extends Controller
{
    /**
     * Get reviews for the authenticated vendor.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'has_response' => 'sometimes|boolean',
            'service_id' => 'sometimes|uuid',
            'search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|in:newest,oldest,rating_high,rating_low,helpful',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1'
        ]);

        try {
            $perPage = $request->integer('per_page', 20);
            $vendorId = auth()->id();

            // Build query
            $query = Review::forVendor($vendorId)
                ->with(['user', 'service'])
                ->latest();

            // Apply filters
            if ($request->filled('rating')) {
                $query->byRating($request->rating);
            }

            if ($request->has('has_response')) {
                if ($request->boolean('has_response')) {
                    $query->withResponse();
                } else {
                    $query->withoutResponse();
                }
            }

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->service_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('comment', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($userQuery) use ($search) {
                          $userQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Apply sorting
            switch ($request->sort) {
                case 'oldest':
                    $query->oldest();
                    break;
                case 'rating_high':
                    $query->orderBy('rating', 'desc');
                    break;
                case 'rating_low':
                    $query->orderBy('rating', 'asc');
                    break;
                case 'helpful':
                    $query->orderBy('helpful_count', 'desc');
                    break;
                default:
                    $query->latest();
            }

            $reviews = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $reviews->items(),
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get review analytics for the vendor.
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $vendorId = auth()->id();
            
            $analytics = Cache::remember(
                "vendor_reviews_analytics_{$vendorId}",
                now()->addMinutes(30),
                function () use ($vendorId) {
                    $reviews = Review::forVendor($vendorId);
                    
                    $totalReviews = $reviews->count();
                    $averageRating = $reviews->avg('rating') ?: 0;
                    $recentReviews = $reviews->recent(30)->count();
                    $responseRate = $this->calculateResponseRate($vendorId);
                    
                    // Rating distribution
                    $ratingDistribution = Review::getRatingDistributionForVendor($vendorId);
                    
                    // Monthly trends (last 12 months)
                    $monthlyTrends = $this->getMonthlyTrends($vendorId);
                    
                    // Recent reviews needing response
                    $needingResponse = Review::forVendor($vendorId)
                        ->withoutResponse()
                        ->latest()
                        ->limit(5)
                        ->with(['user', 'service'])
                        ->get();
                    
                    // Top keywords from reviews
                    $keywords = $this->extractKeywords($vendorId);
                    
                    return [
                        'overview' => [
                            'total_reviews' => $totalReviews,
                            'average_rating' => round($averageRating, 1),
                            'recent_reviews' => $recentReviews,
                            'response_rate' => $responseRate,
                            'reviews_needing_response' => $needingResponse->count(),
                        ],
                        'rating_distribution' => $ratingDistribution,
                        'monthly_trends' => $monthlyTrends,
                        'needing_response' => $needingResponse,
                        'keywords' => $keywords,
                        'service_breakdown' => $this->getServiceBreakdown($vendorId),
                    ];
                }
            );

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch review analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Respond to a review.
     */
    public function respond(Request $request, Review $review): JsonResponse
    {
        $request->validate([
            'response' => 'required|string|max:1000',
        ]);

        try {
            // Check if review belongs to vendor's service
            if ($review->service->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to respond to this review'
                ], 403);
            }

            // Check if already responded
            if ($review->vendor_response) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review already has a response'
                ], 400);
            }

            $review->update([
                'vendor_response' => $request->response,
                'vendor_response_at' => now(),
            ]);

            // Clear analytics cache
            Cache::forget("vendor_reviews_analytics_" . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Response added successfully',
                'data' => $review->fresh(['user', 'service']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add response',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a vendor response.
     */
    public function updateResponse(Request $request, Review $review): JsonResponse
    {
        $request->validate([
            'response' => 'required|string|max:1000',
        ]);

        try {
            // Check if review belongs to vendor's service
            if ($review->service->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this response'
                ], 403);
            }

            $review->update([
                'vendor_response' => $request->response,
                'vendor_response_at' => now(),
            ]);

            // Clear analytics cache
            Cache::forget("vendor_reviews_analytics_" . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Response updated successfully',
                'data' => $review->fresh(['user', 'service']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update response',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a vendor response.
     */
    public function deleteResponse(Request $request, Review $review): JsonResponse
    {
        try {
            // Check if review belongs to vendor's service
            if ($review->service->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this response'
                ], 403);
            }

            $review->update([
                'vendor_response' => null,
                'vendor_response_at' => null,
            ]);

            // Clear analytics cache
            Cache::forget("vendor_reviews_analytics_" . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Response deleted successfully',
                'data' => $review->fresh(['user', 'service']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete response',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendor's services for filtering.
     */
    public function services(Request $request): JsonResponse
    {
        try {
            $services = Service::where('user_id', auth()->id())
                ->select('id', 'title')
                ->withCount('reviews')
                ->having('reviews_count', '>', 0)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $services,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch services',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Helper methods
    private function calculateResponseRate($vendorId): float
    {
        $totalReviews = Review::forVendor($vendorId)->count();
        if ($totalReviews === 0) return 0;
        
        $reviewsWithResponse = Review::forVendor($vendorId)->withResponse()->count();
        return round(($reviewsWithResponse / $totalReviews) * 100, 1);
    }

    private function getMonthlyTrends($vendorId): array
    {
        $trends = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthStart = $date->copy()->startOfMonth();
            $monthEnd = $date->copy()->endOfMonth();
            
            $count = Review::forVendor($vendorId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();
                
            $avgRating = Review::forVendor($vendorId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->avg('rating') ?: 0;
            
            $trends[] = [
                'month' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
                'count' => $count,
                'average_rating' => round($avgRating, 1),
            ];
        }
        
        return $trends;
    }

    private function extractKeywords($vendorId): array
    {
        // Mock keywords - in real implementation, use text analysis
        return [
            ['word' => 'professional', 'count' => 45],
            ['word' => 'quality', 'count' => 38],
            ['word' => 'excellent', 'count' => 32],
            ['word' => 'friendly', 'count' => 28],
            ['word' => 'timely', 'count' => 25],
            ['word' => 'skilled', 'count' => 22],
            ['word' => 'clean', 'count' => 20],
            ['word' => 'satisfied', 'count' => 18],
        ];
    }

    private function getServiceBreakdown($vendorId): array
    {
        return Review::forVendor($vendorId)
            ->join('services', 'reviews.service_id', '=', 'services.id')
            ->selectRaw('services.title, COUNT(*) as review_count, AVG(reviews.rating) as avg_rating')
            ->groupBy('services.id', 'services.title')
            ->orderBy('review_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'service_title' => $item->title,
                    'review_count' => $item->review_count,
                    'average_rating' => round($item->avg_rating, 1),
                ];
            })
            ->toArray();
    }
}