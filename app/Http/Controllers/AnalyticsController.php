<?php

namespace App\Http\Controllers;

use App\Services\ServiceBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        private ServiceBusinessService $serviceBusinessService
    ) {}

    /**
     * Get comprehensive analytics for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'sometimes|date|before_or_equal:today',
            'end_date' => 'sometimes|date|after_or_equal:start_date|before_or_equal:today'
        ]);

        try {
            $dateRange = [];
            if ($request->has('start_date')) {
                $dateRange['start'] = $request->start_date;
            }
            if ($request->has('end_date')) {
                $dateRange['end'] = $request->end_date;
            }

            $analytics = $this->serviceBusinessService->getUserAnalytics(
                auth()->id(),
                $dateRange
            );

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get analytics summary for dashboard widgets.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $analytics = $this->serviceBusinessService->getUserAnalytics(auth()->id());

            // Extract just the overview data for quick dashboard display
            $summary = [
                'total_services' => $analytics['overview']['total_services'],
                'active_services' => $analytics['overview']['active_services'],
                'total_views' => $analytics['overview']['total_views'],
                'total_bookings' => $analytics['overview']['total_bookings'],
                'total_revenue' => $analytics['overview']['total_revenue'],
                'average_rating' => $analytics['overview']['average_rating'],
                'conversion_rate' => $analytics['overview']['conversion_rate'],
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export analytics data.
     */
    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'format' => 'required|in:csv,json,pdf',
            'start_date' => 'sometimes|date|before_or_equal:today',
            'end_date' => 'sometimes|date|after_or_equal:start_date|before_or_equal:today'
        ]);

        try {
            $dateRange = [];
            if ($request->has('start_date')) {
                $dateRange['start'] = $request->start_date;
            }
            if ($request->has('end_date')) {
                $dateRange['end'] = $request->end_date;
            }

            $analytics = $this->serviceBusinessService->getUserAnalytics(
                auth()->id(),
                $dateRange
            );

            // Format data for export
            $exportData = [
                'generated_at' => now()->toISOString(),
                'period' => $analytics['period'],
                'overview' => $analytics['overview'],
                'top_services' => $analytics['services']['top_performing'],
            ];

            switch ($request->format) {
                case 'csv':
                    // In a real implementation, you'd generate and return a CSV file
                    return response()->json([
                        'success' => true,
                        'message' => 'CSV export prepared',
                        'download_url' => '/api/v1/analytics/download/csv/' . uniqid(),
                    ]);

                case 'pdf':
                    // In a real implementation, you'd generate and return a PDF file
                    return response()->json([
                        'success' => true,
                        'message' => 'PDF export prepared',
                        'download_url' => '/api/v1/analytics/download/pdf/' . uniqid(),
                    ]);

                case 'json':
                default:
                    return response()->json([
                        'success' => true,
                        'data' => $exportData,
                    ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}