<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'vendor_id',
        'service_id',
        'amount',
        'platform_fee',
        'vendor_amount',
        'currency',
        'status',
        'payment_method',
        'payment_provider',
        'provider_payment_id',
        'provider_customer_id',
        'processed_at',
        'refunded_at',
        'refund_amount',
        'refund_reason',
        'metadata',
        'provider_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'vendor_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'metadata' => 'array',
        'provider_response' => 'array',
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // Scopes
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    public function scopeThisMonth($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfMonth(),
            now()->endOfMonth()
        ]);
    }

    public function scopeLastMonth($query)
    {
        return $query->whereBetween('created_at', [
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth()
        ]);
    }

    // Accessors
    public function getFormattedAmountAttribute()
    {
        return 'R' . number_format($this->amount, 2);
    }

    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at->format('M j, Y');
    }

    public function getTimeAgoAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'completed' => 'text-green-600 bg-green-100',
            'pending' => 'text-yellow-600 bg-yellow-100',
            'processing' => 'text-blue-600 bg-blue-100',
            'failed' => 'text-red-600 bg-red-100',
            'refunded' => 'text-gray-600 bg-gray-100',
            'disputed' => 'text-purple-600 bg-purple-100',
            default => 'text-gray-600 bg-gray-100',
        };
    }

    public function getIsRefundableAttribute()
    {
        return $this->status === 'completed' && !$this->refunded_at;
    }

    // Static methods
    public static function getTotalRevenueForVendor($vendorId, $startDate = null, $endDate = null)
    {
        $query = static::forVendor($vendorId)->completed();
        
        if ($startDate && $endDate) {
            $query->whereBetween('processed_at', [$startDate, $endDate]);
        }
        
        return $query->sum('vendor_amount');
    }

    public static function getTotalSpentByCustomer($customerId, $startDate = null, $endDate = null)
    {
        $query = static::forCustomer($customerId)->completed();
        
        if ($startDate && $endDate) {
            $query->whereBetween('processed_at', [$startDate, $endDate]);
        }
        
        return $query->sum('amount');
    }

    public static function getMonthlyRevenueForVendor($vendorId, $months = 12)
    {
        $data = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();
            
            $revenue = static::forVendor($vendorId)
                ->completed()
                ->whereBetween('processed_at', [$startOfMonth, $endOfMonth])
                ->sum('vendor_amount');
                
            $data[] = [
                'month' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
                'revenue' => $revenue,
            ];
        }
        
        return $data;
    }
}
