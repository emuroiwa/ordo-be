<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'roles',
        'vendor_verification_status',
        'business_name',
        'business_registration_number',
        'tax_identification_number',
        'business_address',
        'business_description',
        'slug',
        'service_category',
        'is_active',
        'avatar',
        'email_verified',
        'identity_verified',
        'liveness_verified',
        'business_verified',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'vendor_verified_at' => 'datetime',
            'verification_reminder_sent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'business_address' => 'array',
            'is_active' => 'boolean',
            'email_verified' => 'boolean',
            'identity_verified' => 'boolean',
            'liveness_verified' => 'boolean',
            'business_verified' => 'boolean',
        ];
    }

    /**
     * Update the user's last login timestamp.
     */
    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles);
    }

    /**
     * Check if user is a vendor.
     */
    public function isVendor(): bool
    {
        return $this->hasRole('vendor');
    }

    /**
     * Check if user is a customer.
     */
    public function isCustomer(): bool
    {
        return $this->hasRole('customer');
    }

    /**
     * Check if vendor is verified.
     */
    public function isVendorVerified(): bool
    {
        return $this->vendor_verification_status === 'approved';
    }

    /**
     * Check if vendor verification is pending.
     */
    public function isVendorVerificationPending(): bool
    {
        return in_array($this->vendor_verification_status, ['pending', 'in_progress']);
    }

    /**
     * Check if vendor can accept bookings.
     */
    public function canAcceptBookings(): bool
    {
        return $this->isVendor() && $this->isVendorVerified() && $this->is_active;
    }

    /**
     * Get verification status display name.
     */
    public function getVerificationStatusDisplayAttribute(): string
    {
        return match($this->vendor_verification_status) {
            'unverified' => 'Not Started',
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'approved' => 'Verified',
            'rejected' => 'Rejected',
            'suspended' => 'Suspended',
            default => 'Unknown'
        };
    }

    /**
     * Get the user's full avatar URL.
     */
    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return url('storage/' . $this->avatar);
        }
        
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&color=7F9CF5&background=EBF4FF';
    }

    /**
     * Get all notifications for the user.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->orderBy('created_at', 'desc');
    }

    /**
     * Get all services belonging to this user.
     */
    public function services(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Service::class);
    }

    /**
     * Get bookings as a customer.
     */
    public function customerBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    /**
     * Get bookings as a vendor.
     */
    public function vendorBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'vendor_id');
    }

    /**
     * Get availability slots for vendor.
     */
    public function availabilitySlots(): HasMany
    {
        return $this->hasMany(AvailabilitySlot::class, 'vendor_id');
    }

    /**
     * Get reviews written by user as a customer.
     */
    public function reviewsGiven(): HasMany
    {
        return $this->hasMany(BookingReview::class, 'customer_id');
    }

    /**
     * Get reviews received by user as a vendor.
     */
    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(BookingReview::class, 'vendor_id');
    }

    /**
     * Get user's favorite services.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Get vendor verification record.
     */
    public function vendorVerification(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(VendorVerification::class);
    }

    /**
     * Get verification documents.
     */
    public function verificationDocuments(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadNotificationsCount(): int
    {
        return $this->notifications()->unread()->active()->count();
    }

    /**
     * Get recent notifications.
     */
    public function recentNotifications(int $limit = 10)
    {
        return $this->notifications()->active()->limit($limit)->get();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllNotificationsAsRead(): void
    {
        $this->notifications()->unread()->update(['read_at' => now()]);
    }

    /**
     * Generate unique slug from business name or name.
     */
    public function generateSlug(): string
    {
        $name = $this->business_name ?: $this->name;
        $baseSlug = \Illuminate\Support\Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (static::where('slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get route key name for model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Find user by slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
