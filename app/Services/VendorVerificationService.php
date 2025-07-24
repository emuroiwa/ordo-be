<?php

namespace App\Services;

use App\Models\User;
use App\Models\VendorVerification;
use App\Models\VerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorVerificationService
{
    public function __construct(
        private NotificationService $notificationService,
        private EmailVerificationService $emailVerificationService,
        private IdentityVerificationService $identityVerificationService,
        private LivenessVerificationService $livenessVerificationService
    ) {}

    /**
     * Start the vendor verification process for a user.
     */
    public function startVerification(User $user): VendorVerification
    {
        if (!$user->isVendor()) {
            throw new \InvalidArgumentException('User must be a vendor to start verification process.');
        }

        // Check if verification already exists
        $verification = $user->vendorVerification;
        
        if (!$verification) {
            $verification = VendorVerification::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'email_verification_token' => Str::random(64),
            ]);
        }

        // Update user status
        $user->update(['vendor_verification_status' => 'pending']);

        // Send email verification if not already verified
        if (!$verification->isEmailVerified()) {
            $this->emailVerificationService->sendVerificationEmail($verification);
        }

        return $verification;
    }

    /**
     * Verify email address.
     */
    public function verifyEmail(VendorVerification $verification, string $token): bool
    {
        if ($verification->email_verification_token !== $token) {
            return false;
        }

        $verification->update([
            'email_verified_at' => now(),
            'email_verification_token' => null,
        ]);

        $verification->user->update(['email_verified' => true]);
        $verification->updateStatusBasedOnProgress();

        // Send notification
        $this->notificationService->sendEmailVerificationSuccess($verification->user);

        return true;
    }

    /**
     * Upload and process identity document.
     */
    public function uploadIdentityDocument(
        VendorVerification $verification, 
        UploadedFile $file, 
        string $documentType
    ): VerificationDocument {
        if (!$verification->isEmailVerified()) {
            throw new \InvalidArgumentException('Email must be verified before uploading identity documents.');
        }

        $document = $this->storeDocument($verification, $file, $documentType);

        // Start identity verification process
        $this->identityVerificationService->processDocument($document);

        return $document;
    }

    /**
     * Upload and process liveness photo.
     */
    public function uploadLivenessPhoto(
        VendorVerification $verification, 
        UploadedFile $file
    ): bool {
        if (!$verification->isIdentityVerified()) {
            throw new \InvalidArgumentException('Identity must be verified before liveness verification.');
        }

        $path = $this->storeLivenessPhoto($verification, $file);
        
        $verification->update(['liveness_photo_path' => $path]);

        // Start liveness verification process
        return $this->livenessVerificationService->processLivenessPhoto($verification);
    }

    /**
     * Upload business documents.
     */
    public function uploadBusinessDocument(
        VendorVerification $verification,
        UploadedFile $file,
        string $documentType
    ): VerificationDocument {
        if (!$verification->isLivenessVerified()) {
            throw new \InvalidArgumentException('Liveness must be verified before uploading business documents.');
        }

        return $this->storeDocument($verification, $file, $documentType);
    }

    /**
     * Submit for admin review.
     */
    public function submitForReview(VendorVerification $verification): bool
    {
        if (!$verification->isEmailVerified() || 
            !$verification->isIdentityVerified() || 
            !$verification->isLivenessVerified()) {
            throw new \InvalidArgumentException('All verification steps must be completed before submission.');
        }

        // Check if required business documents are uploaded
        $businessDocs = $verification->documents()
            ->businessDocuments()
            ->whereIn('document_type', ['business_registration', 'tax_certificate'])
            ->count();

        if ($businessDocs < 2) {
            throw new \InvalidArgumentException('Business registration and tax certificate are required.');
        }

        $verification->update([
            'status' => 'business_verified',
            'business_verified_at' => now(),
        ]);

        $verification->user->update([
            'business_verified' => true,
            'vendor_verification_status' => 'in_progress'
        ]);

        // Notify admin
        $this->notificationService->sendVerificationSubmittedToAdmin($verification);

        // Notify user
        $this->notificationService->sendVerificationSubmitted($verification->user);

        return true;
    }

    /**
     * Approve vendor verification.
     */
    public function approve(VendorVerification $verification, User $admin, ?string $notes = null): bool
    {
        return DB::transaction(function () use ($verification, $admin, $notes) {
            $verification->update([
                'status' => 'approved',
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'verification_notes' => $notes ? ['approval_notes' => $notes] : null,
            ]);

            $verification->user->update([
                'vendor_verification_status' => 'approved',
                'vendor_verified_at' => now(),
            ]);

            // Send approval notification
            $this->notificationService->sendVerificationApproved($verification->user);

            return true;
        });
    }

    /**
     * Reject vendor verification.
     */
    public function reject(
        VendorVerification $verification, 
        User $admin, 
        array $reasons, 
        ?string $notes = null
    ): bool {
        return DB::transaction(function () use ($verification, $admin, $reasons, $notes) {
            $verification->update([
                'status' => 'rejected',
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reasons' => $reasons,
                'verification_notes' => $notes ? ['rejection_notes' => $notes] : null,
            ]);

            $verification->user->update([
                'vendor_verification_status' => 'rejected',
            ]);

            // Send rejection notification
            $this->notificationService->sendVerificationRejected($verification->user, $reasons);

            return true;
        });
    }

    /**
     * Get verification progress.
     */
    public function getVerificationProgress(User $user): array
    {
        $verification = $user->vendorVerification;
        
        if (!$verification) {
            return [
                'status' => 'not_started',
                'steps' => [
                    'email' => false,
                    'identity' => false,
                    'liveness' => false,
                    'business' => false,
                    'approved' => false
                ],
                'completed' => 0,
                'total' => 5,
                'percentage' => 0,
                'current_step' => 'email'
            ];
        }

        return $verification->getVerificationProgress();
    }

    /**
     * Store uploaded document.
     */
    private function storeDocument(
        VendorVerification $verification, 
        UploadedFile $file, 
        string $documentType
    ): VerificationDocument {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $path = "verification/{$verification->user_id}/{$filename}";

        // Store file securely
        $file->storeAs('verification/' . $verification->user_id, $filename, 'private');

        return VerificationDocument::create([
            'user_id' => $verification->user_id,
            'vendor_verification_id' => $verification->id,
            'document_type' => $documentType,
            'original_filename' => $originalName,
            'file_path' => $path,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'processing_status' => 'uploaded',
            'is_sensitive' => true,
            'expires_at' => now()->addYears(2), // Keep for 2 years
        ]);
    }

    /**
     * Store liveness photo.
     */
    private function storeLivenessPhoto(VendorVerification $verification, UploadedFile $file): string
    {
        $filename = 'liveness_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = "verification/{$verification->user_id}/{$filename}";

        $file->storeAs('verification/' . $verification->user_id, $filename, 'private');

        return $path;
    }

    /**
     * Get pending verifications for admin review.
     */
    public function getPendingVerifications()
    {
        return VendorVerification::with(['user', 'documents'])
            ->awaitingReview()
            ->orderBy('created_at', 'asc')
            ->paginate(20);
    }

    /**
     * Send verification reminders.
     */
    public function sendVerificationReminders(): int
    {
        $users = User::where('vendor_verification_status', 'pending')
            ->where('verification_reminder_count', '<', 3)
            ->where(function ($query) {
                $query->whereNull('verification_reminder_sent_at')
                    ->orWhere('verification_reminder_sent_at', '<', now()->subDays(7));
            })
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            $this->notificationService->sendVerificationReminder($user);
            
            $user->update([
                'verification_reminder_sent_at' => now(),
                'verification_reminder_count' => $user->verification_reminder_count + 1,
            ]);
            
            $sent++;
        }

        return $sent;
    }

    /**
     * Resend email verification for a vendor.
     */
    public function resendEmailVerification(VendorVerification $verification): void
    {
        $user = $verification->user;
        
        // Check if email is already verified
        if ($user->email_verified_at || $verification->email_verified_at) {
            throw new \InvalidArgumentException('Email is already verified.');
        }

        // Generate new token if needed
        if (!$verification->email_verification_token) {
            $verification->update([
                'email_verification_token' => Str::random(60),
            ]);
        }

        // Send email verification
        $this->emailVerificationService->sendVerificationEmail($user, $verification);

        // Update last attempt timestamp
        $verification->update([
            'updated_at' => now(),
        ]);
    }
}