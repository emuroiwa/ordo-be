<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorVerification;
use App\Services\VendorVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class VendorVerificationController extends Controller
{
    public function __construct(
        private VendorVerificationService $verificationService
    ) {}

    /**
     * Get pending verifications for admin review.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = VendorVerification::with(['user', 'documents'])
                ->when($request->input('status'), function ($query, $status) {
                    return $query->where('status', $status);
                })
                ->when($request->input('search'), function ($query, $search) {
                    return $query->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('business_name', 'like', "%{$search}%");
                    });
                })
                ->orderBy('created_at', 'desc');

            $verifications = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $verifications,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch verifications.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific verification details.
     */
    public function show(VendorVerification $verification): JsonResponse
    {
        try {
            $verification->load([
                'user',
                'documents' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'verifiedBy',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'verification' => $verification,
                    'progress' => $verification->getVerificationProgress(),
                    'documents_count' => $verification->documents->count(),
                    'identity_documents' => $verification->documents()->identityDocuments()->get(),
                    'business_documents' => $verification->documents()->businessDocuments()->get(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch verification details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve vendor verification.
     */
    public function approve(Request $request, VendorVerification $verification): JsonResponse
    {
        try {
            $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $admin = Auth::user();
            $success = $this->verificationService->approve(
                $verification,
                $admin,
                $request->input('notes')
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vendor verification approved successfully.',
                    'data' => [
                        'verification' => $verification->fresh(),
                        'status' => $verification->status,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve verification.',
            ], 500);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve verification.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject vendor verification.
     */
    public function reject(Request $request, VendorVerification $verification): JsonResponse
    {
        try {
            $request->validate([
                'reasons' => 'required|array|min:1',
                'reasons.*' => 'required|string|in:poor_document_quality,invalid_documents,identity_mismatch,business_info_incorrect,suspicious_activity,incomplete_information,other',
                'notes' => 'nullable|string|max:1000',
            ]);

            $admin = Auth::user();
            $success = $this->verificationService->reject(
                $verification,
                $admin,
                $request->input('reasons'),
                $request->input('notes')
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vendor verification rejected successfully.',
                    'data' => [
                        'verification' => $verification->fresh(),
                        'status' => $verification->status,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject verification.',
            ], 500);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject verification.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get verification statistics.
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_verifications' => VendorVerification::count(),
                'pending_review' => VendorVerification::awaitingReview()->count(),
                'approved' => VendorVerification::approved()->count(),
                'rejected' => VendorVerification::rejected()->count(),
                'in_progress' => VendorVerification::whereIn('status', [
                    'email_verified',
                    'identity_verified',
                    'liveness_verified'
                ])->count(),
                'recent_submissions' => VendorVerification::where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'average_processing_time' => $this->calculateAverageProcessingTime(),
                'completion_rate' => $this->calculateCompletionRate(),
            ];

            $recentActivity = VendorVerification::with('user')
                ->whereIn('status', ['approved', 'rejected'])
                ->orderBy('verified_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($verification) {
                    return [
                        'id' => $verification->id,
                        'user_name' => $verification->user->name,
                        'business_name' => $verification->user->business_name,
                        'status' => $verification->status,
                        'verified_at' => $verification->verified_at,
                        'processing_days' => $verification->created_at->diffInDays($verification->verified_at),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => $stats,
                    'recent_activity' => $recentActivity,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get document download URL.
     */
    public function downloadDocument(Request $request, string $documentId): JsonResponse
    {
        try {
            $document = \App\Models\VerificationDocument::findOrFail($documentId);

            // Generate temporary download URL
            $url = $document->getTemporaryUrl(60); // Valid for 1 hour

            if (!$url) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found or expired.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $url,
                    'filename' => $document->original_filename,
                    'expires_at' => now()->addHour()->toISOString(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate download URL.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk approve verifications.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'verification_ids' => 'required|array|min:1|max:50',
                'verification_ids.*' => 'required|uuid|exists:vendor_verifications,id',
                'notes' => 'nullable|string|max:500',
            ]);

            $admin = Auth::user();
            $verificationIds = $request->input('verification_ids');
            $notes = $request->input('notes');

            $approved = 0;
            $failed = [];

            foreach ($verificationIds as $verificationId) {
                try {
                    $verification = VendorVerification::findOrFail($verificationId);
                    
                    if ($verification->status === 'business_verified') {
                        $this->verificationService->approve($verification, $admin, $notes);
                        $approved++;
                    } else {
                        $failed[] = [
                            'id' => $verificationId,
                            'reason' => 'Invalid status for approval'
                        ];
                    }
                } catch (\Exception $e) {
                    $failed[] = [
                        'id' => $verificationId,
                        'reason' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully approved {$approved} verifications.",
                'data' => [
                    'approved_count' => $approved,
                    'failed_count' => count($failed),
                    'failed_items' => $failed,
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk approval failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate average processing time in days.
     */
    private function calculateAverageProcessingTime(): float
    {
        $completed = VendorVerification::whereIn('status', ['approved', 'rejected'])
            ->whereNotNull('verified_at')
            ->get();

        if ($completed->isEmpty()) {
            return 0;
        }

        $totalDays = $completed->sum(function ($verification) {
            return $verification->created_at->diffInDays($verification->verified_at);
        });

        return round($totalDays / $completed->count(), 1);
    }

    /**
     * Calculate completion rate percentage.
     */
    private function calculateCompletionRate(): float
    {
        $total = VendorVerification::count();
        
        if ($total === 0) {
            return 0;
        }

        $completed = VendorVerification::whereIn('status', ['approved', 'rejected'])->count();

        return round(($completed / $total) * 100, 1);
    }
}