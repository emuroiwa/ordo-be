<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class VerificationDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'vendor_verification_id',
        'document_type',
        'original_filename',
        'file_path',
        'file_hash',
        'file_size',
        'mime_type',
        'processing_status',
        'extracted_data',
        'validation_results',
        'rejection_reason',
        'processor_service',
        'processor_reference',
        'processed_at',
        'processed_by',
        'is_sensitive',
        'expires_at',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'validation_results' => 'array',
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_sensitive' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendorVerification(): BelongsTo
    {
        return $this->belongsTo(VendorVerification::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Status check methods
    public function isUploaded(): bool
    {
        return $this->processing_status === 'uploaded';
    }

    public function isProcessing(): bool
    {
        return $this->processing_status === 'processing';
    }

    public function isProcessed(): bool
    {
        return $this->processing_status === 'processed';
    }

    public function isVerified(): bool
    {
        return $this->processing_status === 'verified';
    }

    public function isRejected(): bool
    {
        return $this->processing_status === 'rejected';
    }

    public function isExpired(): bool
    {
        return $this->processing_status === 'expired' || 
               ($this->expires_at && $this->expires_at->isPast());
    }

    // File operations
    public function getFileUrl(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk('private')->url($this->file_path);
    }

    public function getTemporaryUrl(int $minutes = 60): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk('private')->temporaryUrl(
            $this->file_path,
            now()->addMinutes($minutes)
        );
    }

    public function deleteFile(): bool
    {
        if ($this->file_path && Storage::disk('private')->exists($this->file_path)) {
            return Storage::disk('private')->delete($this->file_path);
        }
        
        return true;
    }

    // Document type helpers
    public function isIdentityDocument(): bool
    {
        return in_array($this->document_type, [
            'national_id',
            'passport', 
            'drivers_license'
        ]);
    }

    public function isBusinessDocument(): bool
    {
        return in_array($this->document_type, [
            'business_registration',
            'tax_certificate',
            'bank_statement',
            'proof_of_address',
            'professional_license'
        ]);
    }

    // Validation helpers
    public function hasValidationResults(): bool
    {
        return !empty($this->validation_results);
    }

    public function getValidationScore(): ?float
    {
        if (!$this->hasValidationResults()) {
            return null;
        }

        return $this->validation_results['overall_score'] ?? null;
    }

    public function passedValidation(): bool
    {
        $score = $this->getValidationScore();
        return $score !== null && $score >= 0.8; // 80% confidence threshold
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeIdentityDocuments($query)
    {
        return $query->whereIn('document_type', [
            'national_id',
            'passport',
            'drivers_license'
        ]);
    }

    public function scopeBusinessDocuments($query)
    {
        return $query->whereIn('document_type', [
            'business_registration',
            'tax_certificate',
            'bank_statement',
            'proof_of_address',
            'professional_license'
        ]);
    }

    public function scopeVerified($query)
    {
        return $query->where('processing_status', 'verified');
    }

    public function scopePendingReview($query)
    {
        return $query->where('processing_status', 'processed');
    }

    // Boot method for automatic cleanup
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($document) {
            $document->deleteFile();
        });
    }
}