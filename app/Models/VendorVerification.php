<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorVerification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'status',
        'email_verified_at',
        'email_verification_token',
        'identity_documents',
        'identity_verified_at',
        'identity_verification_data',
        'identity_verification_reference',
        'liveness_photo_path',
        'liveness_verified_at',
        'liveness_verification_data',
        'liveness_verification_reference',
        'business_registration_number',
        'tax_identification_number',
        'business_address',
        'business_documents',
        'business_verified_at',
        'verification_notes',
        'rejection_reasons',
        'verified_by',
        'verified_at',
        'identity_service_provider',
        'liveness_service_provider',
        'verification_attempts',
        'last_attempt_at',
    ];

    protected $casts = [
        'identity_documents' => 'array',
        'identity_verification_data' => 'array',
        'liveness_verification_data' => 'array',
        'business_address' => 'array',
        'business_documents' => 'array',
        'verification_notes' => 'array',
        'rejection_reasons' => 'array',
        'email_verified_at' => 'datetime',
        'identity_verified_at' => 'datetime',
        'liveness_verified_at' => 'datetime',
        'business_verified_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    // Status check methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isEmailVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }

    public function isIdentityVerified(): bool
    {
        return !is_null($this->identity_verified_at);
    }

    public function isLivenessVerified(): bool
    {
        return !is_null($this->liveness_verified_at);
    }

    public function isBusinessVerified(): bool
    {
        return !is_null($this->business_verified_at);
    }

    public function isFullyVerified(): bool
    {
        return $this->isEmailVerified() && 
               $this->isIdentityVerified() && 
               $this->isLivenessVerified() && 
               $this->isBusinessVerified() &&
               $this->isApproved();
    }

    // Progress calculation
    public function getVerificationProgress(): array
    {
        $steps = [
            'email' => $this->isEmailVerified(),
            'identity' => $this->isIdentityVerified(),
            'liveness' => $this->isLivenessVerified(),
            'business' => $this->isBusinessVerified(),
            'approved' => $this->isApproved()
        ];

        $completed = array_sum($steps);
        $total = count($steps);
        $percentage = round(($completed / $total) * 100);

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percentage' => $percentage,
            'current_step' => $this->getCurrentStep()
        ];
    }

    public function getCurrentStep(): string
    {
        if (!$this->isEmailVerified()) return 'email';
        if (!$this->isIdentityVerified()) return 'identity';
        if (!$this->isLivenessVerified()) return 'liveness';
        if (!$this->isBusinessVerified()) return 'business';
        if ($this->status === 'business_verified') return 'review';
        return 'completed';
    }

    // Update verification status based on completed steps
    public function updateStatusBasedOnProgress(): void
    {
        if ($this->isApproved() || $this->isRejected()) {
            return; // Don't change final statuses
        }

        if ($this->isEmailVerified() && !$this->isIdentityVerified()) {
            $this->status = 'email_verified';
        } elseif ($this->isIdentityVerified() && !$this->isLivenessVerified()) {
            $this->status = 'identity_verified';
        } elseif ($this->isLivenessVerified() && !$this->isBusinessVerified()) {
            $this->status = 'liveness_verified';
        } elseif ($this->isBusinessVerified()) {
            $this->status = 'business_verified';
        }

        $this->save();
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAwaitingReview($query)
    {
        return $query->where('status', 'business_verified');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}