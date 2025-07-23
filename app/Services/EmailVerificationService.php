<?php

namespace App\Services;

use App\Models\VendorVerification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class EmailVerificationService
{
    /**
     * Send email verification for vendor verification.
     */
    public function sendVerificationEmail(VendorVerification $verification): bool
    {
        try {
            $verificationUrl = $this->generateVerificationUrl($verification);
            
            Mail::send('emails.vendor.verify-email', [
                'user' => $verification->user,
                'verification' => $verification,
                'verificationUrl' => $verificationUrl,
            ], function ($message) use ($verification) {
                $message->to($verification->user->email, $verification->user->name);
                $message->subject('Verify Your Email - ORDO Vendor Registration');
            });

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send vendor email verification', [
                'user_id' => $verification->user_id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Generate email verification URL.
     */
    public function generateVerificationUrl(VendorVerification $verification): string
    {
        return URL::temporarySignedRoute(
            'vendor.verification.verify-email',
            now()->addHours(24),
            [
                'id' => $verification->id,
                'token' => $verification->email_verification_token,
            ]
        );
    }

    /**
     * Resend verification email.
     */
    public function resendVerificationEmail(VendorVerification $verification): bool
    {
        // Rate limiting: Allow resend only after 5 minutes
        if ($verification->updated_at && $verification->updated_at->diffInMinutes(now()) < 5) {
            throw new \InvalidArgumentException('Please wait 5 minutes before requesting another verification email.');
        }

        // Generate new token
        $verification->update([
            'email_verification_token' => \Illuminate\Support\Str::random(64),
        ]);

        return $this->sendVerificationEmail($verification);
    }
}