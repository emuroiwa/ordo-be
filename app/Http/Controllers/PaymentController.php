<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /**
     * Get payments dashboard data for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $isVendor = in_array('vendor', $user->roles ?? []);
            
            if ($isVendor) {
                $data = $this->getVendorPaymentsData($user->id);
            } else {
                $data = $this->getCustomerPaymentsData($user->id);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment history with filters.
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'sometimes|in:pending,processing,completed,failed,refunded,disputed',
            'payment_method' => 'sometimes|in:card,bank_transfer,wallet,cash',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1'
        ]);

        try {
            $user = auth()->user();
            $isVendor = in_array('vendor', $user->roles ?? []);
            $perPage = $request->integer('per_page', 20);

            // Build query based on user role
            if ($isVendor) {
                $query = Payment::forVendor($user->id);
            } else {
                $query = Payment::forCustomer($user->id);
            }

            $query->with(['customer', 'vendor', 'service', 'booking']);

            // Apply filters
            if ($request->filled('status')) {
                $query->byStatus($request->status);
            }

            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('provider_payment_id', 'like', "%{$search}%")
                      ->orWhereHas('service', function ($serviceQuery) use ($search) {
                          $serviceQuery->where('title', 'like', "%{$search}%");
                      })
                      ->orWhereHas('customer', function ($customerQuery) use ($search) {
                          $customerQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $payments = $query->latest()->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $payments->items(),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|in:payment,refund,payout,fee,adjustment',
            'status' => 'sometimes|in:pending,processing,completed,failed,cancelled',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1'
        ]);

        try {
            $user = auth()->user();
            $perPage = $request->integer('per_page', 20);

            $query = Transaction::forUser($user->id)->with('payment');

            // Apply filters
            if ($request->filled('type')) {
                $query->byType($request->type);
            }

            if ($request->filled('status')) {
                $query->byStatus($request->status);
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
            }

            $transactions = $query->latest()->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
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
     * Get payment methods for the authenticated user.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $paymentMethods = PaymentMethod::forUser($user->id)
                ->active()
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paymentMethods,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a new payment method.
     */
    public function addPaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:card,bank_account,wallet',
            'provider' => 'sometimes|string',
            'provider_method_id' => 'sometimes|string',
            'last_four' => 'required|string|size:4',
            'brand' => 'sometimes|string',
            'account_holder_name' => 'sometimes|string',
            'bank_name' => 'sometimes|string',
            'exp_month' => 'sometimes|integer|min:1|max:12',
            'exp_year' => 'sometimes|integer|min:' . date('Y'),
            'is_default' => 'sometimes|boolean',
        ]);

        try {
            $user = auth()->user();

            $paymentMethod = PaymentMethod::create([
                'user_id' => $user->id,
                'type' => $request->type,
                'provider' => $request->provider,
                'provider_method_id' => $request->provider_method_id,
                'last_four' => $request->last_four,
                'brand' => $request->brand,
                'account_holder_name' => $request->account_holder_name,
                'bank_name' => $request->bank_name,
                'exp_month' => $request->exp_month,
                'exp_year' => $request->exp_year,
                'is_default' => $request->boolean('is_default', false),
            ]);

            if ($request->boolean('is_default')) {
                PaymentMethod::setDefaultForUser($user->id, $paymentMethod->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully',
                'data' => $paymentMethod,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set default payment method.
     */
    public function setDefaultPaymentMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check ownership
            if ($paymentMethod->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to modify this payment method'
                ], 403);
            }

            PaymentMethod::setDefaultForUser($user->id, $paymentMethod->id);

            return response()->json([
                'success' => true,
                'message' => 'Default payment method updated successfully',
                'data' => $paymentMethod->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update default payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a payment method.
     */
    public function deletePaymentMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check ownership
            if ($paymentMethod->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this payment method'
                ], 403);
            }

            $paymentMethod->delete();

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $isVendor = in_array('vendor', $user->roles ?? []);
            
            $analytics = Cache::remember(
                "payment_analytics_{$user->id}_{$isVendor}",
                now()->addMinutes(30),
                function () use ($user, $isVendor) {
                    if ($isVendor) {
                        return $this->getVendorAnalytics($user->id);
                    } else {
                        return $this->getCustomerAnalytics($user->id);
                    }
                }
            );

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Helper methods
    private function getVendorPaymentsData($userId): array
    {
        $thisMonth = Payment::forVendor($userId)->thisMonth();
        $lastMonth = Payment::forVendor($userId)->lastMonth();
        
        $thisMonthRevenue = $thisMonth->completed()->sum('vendor_amount');
        $lastMonthRevenue = $lastMonth->completed()->sum('vendor_amount');
        $thisMonthPayments = $thisMonth->count();
        $pendingPayments = Payment::forVendor($userId)->pending()->count();
        
        $growthPercentage = $lastMonthRevenue > 0 
            ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 
            : 0;

        // Recent payments
        $recentPayments = Payment::forVendor($userId)
            ->with(['customer', 'service'])
            ->latest()
            ->limit(5)
            ->get();

        // Monthly revenue trends
        $monthlyRevenue = Payment::getMonthlyRevenueForVendor($userId);

        // Payment method breakdown
        $paymentMethods = Payment::forVendor($userId)
            ->completed()
            ->selectRaw('payment_method, COUNT(*) as count, SUM(vendor_amount) as total')
            ->groupBy('payment_method')
            ->get();

        return [
            'overview' => [
                'this_month_revenue' => $thisMonthRevenue,
                'this_month_payments' => $thisMonthPayments,
                'pending_payments' => $pendingPayments,
                'growth_percentage' => round($growthPercentage, 1),
            ],
            'recent_payments' => $recentPayments,
            'monthly_revenue' => $monthlyRevenue,
            'payment_methods' => $paymentMethods,
        ];
    }

    private function getCustomerPaymentsData($userId): array
    {
        $thisMonth = Payment::forCustomer($userId)->thisMonth();
        $lastMonth = Payment::forCustomer($userId)->lastMonth();
        
        $thisMonthSpent = $thisMonth->completed()->sum('amount');
        $lastMonthSpent = $lastMonth->completed()->sum('amount');
        $thisMonthPayments = $thisMonth->count();
        $pendingPayments = Payment::forCustomer($userId)->pending()->count();
        
        $growthPercentage = $lastMonthSpent > 0 
            ? (($thisMonthSpent - $lastMonthSpent) / $lastMonthSpent) * 100 
            : 0;

        // Recent payments
        $recentPayments = Payment::forCustomer($userId)
            ->with(['vendor', 'service'])
            ->latest()
            ->limit(5)
            ->get();

        // Monthly spending trends
        $monthlySpending = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();
            
            $spent = Payment::forCustomer($userId)
                ->completed()
                ->whereBetween('processed_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');
                
            $monthlySpending[] = [
                'month' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
                'amount' => $spent,
            ];
        }

        // Payment method breakdown
        $paymentMethods = Payment::forCustomer($userId)
            ->completed()
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        return [
            'overview' => [
                'this_month_spent' => $thisMonthSpent,
                'this_month_payments' => $thisMonthPayments,
                'pending_payments' => $pendingPayments,
                'growth_percentage' => round($growthPercentage, 1),
            ],
            'recent_payments' => $recentPayments,
            'monthly_spending' => $monthlySpending,
            'payment_methods' => $paymentMethods,
        ];
    }

    private function getVendorAnalytics($userId): array
    {
        // Implementation for vendor analytics
        return [
            'revenue_trends' => Payment::getMonthlyRevenueForVendor($userId),
            'payment_status_breakdown' => Payment::forVendor($userId)
                ->selectRaw('status, COUNT(*) as count, SUM(vendor_amount) as total')
                ->groupBy('status')
                ->get(),
            'top_services' => Payment::forVendor($userId)
                ->completed()
                ->join('services', 'payments.service_id', '=', 'services.id')
                ->selectRaw('services.title, COUNT(*) as payment_count, SUM(payments.vendor_amount) as total_revenue')
                ->groupBy('services.id', 'services.title')
                ->orderBy('total_revenue', 'desc')
                ->limit(10)
                ->get(),
        ];
    }

    private function getCustomerAnalytics($userId): array
    {
        // Implementation for customer analytics
        return [
            'spending_trends' => Payment::forCustomer($userId)
                ->completed()
                ->selectRaw('DATE_FORMAT(processed_at, "%Y-%m") as month, SUM(amount) as total')
                ->groupBy('month')
                ->orderBy('month')
                ->get(),
            'favorite_services' => Payment::forCustomer($userId)
                ->completed()
                ->join('services', 'payments.service_id', '=', 'services.id')
                ->selectRaw('services.title, COUNT(*) as booking_count, SUM(payments.amount) as total_spent')
                ->groupBy('services.id', 'services.title')
                ->orderBy('booking_count', 'desc')
                ->limit(10)
                ->get(),
        ];
    }
}