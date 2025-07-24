<?php

namespace App\Http\Controllers;

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
     * Start vendor verification process.
     */
    public function start(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user->isVendor()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only vendors can start the verification process.',
                ], 403);
            }

            $verification = $this->verificationService->startVerification($user);

            return response()->json([
                'success' => true,
                'message' => 'Verification process started successfully. Please check your email.',
                'data' => [
                    'verification_id' => $verification->id,
                    'status' => $verification->status,
                    'progress' => $verification->getVerificationProgress(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start verification process.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get verification status and progress.
     */
    public function status(): JsonResponse
    {
        $user = Auth::user();
        $progress = $this->verificationService->getVerificationProgress($user);

        return response()->json([
            'success' => true,
            'data' => $progress,
        ]);
    }

    /**
     * Verify email with token.
     */
    public function verifyEmail(string $id, string $token): JsonResponse
    {
        try {
            $verification = VendorVerification::findOrFail($id);
            
            // Check if user owns this verification
            if ($verification->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to verification.',
                ], 403);
            }

            $success = $this->verificationService->verifyEmail($verification, $token);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email verified successfully!',
                    'data' => [
                        'status' => $verification->fresh()->status,
                        'progress' => $verification->getVerificationProgress(),
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Email verification failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload identity document.
     */
    public function uploadIdentityDocument(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
                'document_type' => 'required|in:national_id,passport,drivers_license',
            ]);

            $user = Auth::user();
            $verification = $user->vendorVerification;

            if (!$verification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verification process not started.',
                ], 400);
            }

            $document = $this->verificationService->uploadIdentityDocument(
                $verification,
                $request->file('document'),
                $request->input('document_type')
            );

            return response()->json([
                'success' => true,
                'message' => 'Identity document uploaded successfully. Processing in progress.',
                'data' => [
                    'document_id' => $document->id,
                    'processing_status' => $document->processing_status,
                    'progress' => $verification->getVerificationProgress(),
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
                'message' => 'Failed to upload identity document.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload liveness photo.
     */
    public function uploadLivenessPhoto(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'photo' => 'required|file|mimes:jpg,jpeg,png|max:5120', // 5MB max
            ]);

            $user = Auth::user();
            $verification = $user->vendorVerification;

            if (!$verification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verification process not started.',
                ], 400);
            }

            // Check if identity verification is completed first
            if (!$verification->isIdentityVerified()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please complete identity verification before uploading liveness photo.',
                ], 400);
            }

            $success = $this->verificationService->uploadLivenessPhoto(
                $verification,
                $request->file('photo')
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Liveness photo processed successfully!',
                    'data' => [
                        'progress' => $verification->getVerificationProgress(),
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Liveness verification failed. Please try again with a clearer photo.',
            ], 400);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process liveness photo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload business document.
     */
    public function uploadBusinessDocument(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
                'document_type' => 'required|in:business_registration,tax_certificate,bank_statement,proof_of_address,professional_license',
            ]);

            $user = Auth::user();
            $verification = $user->vendorVerification;

            if (!$verification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verification process not started.',
                ], 400);
            }

            $document = $this->verificationService->uploadBusinessDocument(
                $verification,
                $request->file('document'),
                $request->input('document_type')
            );

            return response()->json([
                'success' => true,
                'message' => 'Business document uploaded successfully.',
                'data' => [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type,
                    'progress' => $verification->getVerificationProgress(),
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
                'message' => 'Failed to upload business document.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit for admin review.
     */
    public function submitForReview(): JsonResponse
    {
        try {
            $user = Auth::user();
            $verification = $user->vendorVerification;

            if (!$verification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verification process not started.',
                ], 400);
            }

            $success = $this->verificationService->submitForReview($verification);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Verification submitted for admin review successfully!',
                    'data' => [
                        'status' => $verification->fresh()->status,
                        'progress' => $verification->getVerificationProgress(),
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit for review.',
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit for review.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get verification documents.
     */
    public function documents(): JsonResponse
    {
        $user = Auth::user();
        $verification = $user->vendorVerification;

        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'Verification process not started.',
            ], 400);
        }

        $documents = $verification->documents()
            ->select(['id', 'document_type', 'original_filename', 'processing_status', 'created_at'])
            ->get()
            ->groupBy('document_type');

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * Get verification requirements.
     */
    public function requirements(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'steps' => [
                    [
                        'id' => 'email',
                        'title' => 'Email Verification',
                        'description' => 'Verify your email address to continue.',
                        'required' => true,
                    ],
                    [
                        'id' => 'identity',
                        'title' => 'Identity Verification',
                        'description' => 'Upload a clear photo of your ID, passport, or driver\'s license.',
                        'required' => true,
                        'accepted_documents' => ['national_id', 'passport', 'drivers_license'],
                    ],
                    [
                        'id' => 'liveness',
                        'title' => 'Liveness Verification',
                        'description' => 'Take a selfie to verify you are a real person.',
                        'required' => true,
                    ],
                    [
                        'id' => 'business',
                        'title' => 'Business Documentation',
                        'description' => 'Upload business registration and tax certificates.',
                        'required' => true,
                        'accepted_documents' => [
                            'business_registration',
                            'tax_certificate',
                            'bank_statement',
                            'proof_of_address',
                            'professional_license'
                        ],
                    ],
                    [
                        'id' => 'review',
                        'title' => 'Admin Review',
                        'description' => 'Our team will review your documents within 1-3 business days.',
                        'required' => true,
                    ],
                ],
                'file_requirements' => [
                    'max_file_size' => '10MB',
                    'accepted_formats' => ['jpg', 'jpeg', 'png', 'pdf'],
                    'image_quality' => 'High resolution, clear and readable',
                ],
            ],
        ]);
    }

    /**
     * Resend email verification.
     */
    public function resendEmailVerification(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user->isVendor()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only vendors can request email verification.',
                ], 403);
            }

            // Check if email is already verified
            if ($user->email_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is already verified.',
                ], 400);
            }

            // Check rate limiting (max 3 emails per hour)
            $cacheKey = "email_verification_resend:{$user->id}";
            $attempts = cache()->get($cacheKey, 0);
            
            if ($attempts >= 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many email verification requests. Please wait before trying again.',
                    'retry_after' => 3600, // 1 hour
                ], 429);
            }

            // Get or create verification record
            $verification = VendorVerification::where('user_id', $user->id)->first();
            
            if (!$verification) {
                // Create verification if it doesn't exist
                $verification = $this->verificationService->startVerification($user);
            } else {
                // Resend email using the verification service
                $this->verificationService->resendEmailVerification($verification);
            }

            // Update rate limiting counter
            cache()->put($cacheKey, $attempts + 1, 3600); // 1 hour TTL

            return response()->json([
                'success' => true,
                'message' => 'Verification email sent successfully.',
                'data' => [
                    'email' => $user->email,
                    'expires_in' => '24 hours',
                    'remaining_attempts' => 2 - $attempts,
                ],
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to resend email verification', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email. Please try again later.',
            ], 500);
        }
    }
}