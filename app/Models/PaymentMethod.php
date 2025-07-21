<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'type',
        'provider',
        'provider_method_id',
        'is_default',
        'last_four',
        'brand',
        'account_holder_name',
        'bank_name',
        'exp_month',
        'exp_year',
        'metadata',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
        'metadata' => 'array',
        'exp_month' => 'integer',
        'exp_year' => 'integer',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Accessors
    public function getDisplayNameAttribute()
    {
        if ($this->type === 'card') {
            return ucfirst($this->brand) . ' •••• ' . $this->last_four;
        }
        
        if ($this->type === 'bank_account') {
            return $this->bank_name . ' •••• ' . $this->last_four;
        }
        
        return ucfirst($this->type);
    }

    public function getIsExpiredAttribute()
    {
        if ($this->type !== 'card' || !$this->exp_month || !$this->exp_year) {
            return false;
        }
        
        $expiryDate = mktime(0, 0, 0, $this->exp_month + 1, 1, $this->exp_year);
        return time() > $expiryDate;
    }

    public function getFormattedExpiryAttribute()
    {
        if ($this->type !== 'card' || !$this->exp_month || !$this->exp_year) {
            return null;
        }
        
        return sprintf('%02d/%d', $this->exp_month, $this->exp_year);
    }

    public function getTypeIconAttribute()
    {
        return match($this->type) {
            'card' => 'credit-card',
            'bank_account' => 'building-library',
            'wallet' => 'wallet',
            default => 'banknotes',
        };
    }

    // Static methods
    public static function setDefaultForUser($userId, $paymentMethodId)
    {
        // Remove default from all other payment methods
        static::forUser($userId)->update(['is_default' => false]);
        
        // Set the new default
        static::where('id', $paymentMethodId)
            ->where('user_id', $userId)
            ->update(['is_default' => true]);
    }
}
