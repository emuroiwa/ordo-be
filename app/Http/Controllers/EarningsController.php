<?php

namespace App\Http\Controllers;

use App\Services\ServiceBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    public function __construct(
        private ServiceBusinessService $serviceBusinessService
    ) {}

    /**
     * Get comprehensive earnings data for the authenticated user.
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

            $earnings = $this->serviceBusinessService->getUserEarnings(
                auth()->id(),
                $dateRange
            );

            return response()->json([
                'success' => true,
                'data' => $earnings,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch earnings data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get earnings summary for dashboard widgets.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $earnings = $this->serviceBusinessService->getUserEarnings(auth()->id());

            // Extract just the overview data for quick dashboard display
            $summary = [
                'total_earnings' => $earnings['overview']['total_earnings'],
                'available_balance' => $earnings['overview']['available_balance'],
                'pending_payouts' => $earnings['overview']['pending_payouts'],
                'earnings_this_month' => $earnings['overview']['earnings_this_month'],
                'growth_percentage' => $earnings['overview']['growth_percentage'],
                'average_order_value' => $earnings['overview']['average_order_value'],
                'completion_rate' => $earnings['overview']['completion_rate'],
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch earnings summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request a payout.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:10|max:50000',
            'payout_method' => 'required|in:bank_transfer,paypal,payfast',
            'bank_details' => 'required_if:payout_method,bank_transfer|array',
            'bank_details.account_number' => 'required_if:payout_method,bank_transfer|string',
            'bank_details.account_holder' => 'required_if:payout_method,bank_transfer|string',
            'bank_details.bank_name' => 'required_if:payout_method,bank_transfer|string',
            'bank_details.branch_code' => 'required_if:payout_method,bank_transfer|string',
        ]);

        try {
            $payoutDetails = [
                'method' => $request->payout_method,
                'bank_details' => $request->bank_details ?? null,
            ];

            $payout = $this->serviceBusinessService->requestPayout(
                auth()->id(),
                $request->amount,
                $payoutDetails
            );

            return response()->json([
                'success' => true,
                'message' => 'Payout request submitted successfully',
                'data' => $payout,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get transaction history with filtering.
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|in:earning,payout,all',
            'status' => 'sometimes|in:completed,pending,processing,failed',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1'
        ]);

        try {
            // In a real implementation, this would query the database
            // For now, return mock paginated transaction data
            $perPage = $request->integer('per_page', 20);
            $page = $request->integer('page', 1);
            
            $earnings = $this->serviceBusinessService->getUserEarnings(auth()->id());
            $allTransactions = $earnings['transactions']['recent'];
            
            // Filter transactions based on request parameters
            $filteredTransactions = collect($allTransactions);
            
            if ($request->has('type') && $request->type !== 'all') {
                $filteredTransactions = $filteredTransactions->where('type', $request->type);
            }
            
            if ($request->has('status')) {
                $filteredTransactions = $filteredTransactions->where('status', $request->status);
            }
            
            // Simulate pagination
            $total = $filteredTransactions->count();
            $offset = ($page - 1) * $perPage;
            $paginatedTransactions = $filteredTransactions->slice($offset, $perPage)->values();
            
            return response()->json([
                'success' => true,
                'data' => $paginatedTransactions,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transaction history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payout methods and settings.
     */
    public function payoutMethods(Request $request): JsonResponse
    {
        try {
            // In a real implementation, this would fetch user's saved payout methods
            $methods = [
                [
                    'id' => 'bank_transfer',
                    'name' => 'Bank Transfer',
                    'description' => 'Direct transfer to your bank account',
                    'processing_time' => '1-3 business days',
                    'fee_percentage' => 2.5,
                    'minimum_amount' => 10,
                    'maximum_amount' => 50000,
                    'available' => true,
                ],
                [
                    'id' => 'payfast',
                    'name' => 'PayFast',
                    'description' => 'Instant transfer via PayFast',
                    'processing_time' => 'Instant',
                    'fee_percentage' => 3.5,
                    'minimum_amount' => 10,
                    'maximum_amount' => 10000,
                    'available' => true,
                ],
                [
                    'id' => 'paypal',
                    'name' => 'PayPal',
                    'description' => 'Transfer to your PayPal account',
                    'processing_time' => '1-2 business days',
                    'fee_percentage' => 3.0,
                    'minimum_amount' => 10,
                    'maximum_amount' => 25000,
                    'available' => false, // Coming soon
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $methods,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payout methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}