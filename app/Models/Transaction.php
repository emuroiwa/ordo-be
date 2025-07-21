<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'payment_id',
        'type',
        'amount',
        'currency',
        'status',
        'reference',
        'description',
        'metadata',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // Scopes
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
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

    public function scopeCredits($query)
    {
        return $query->whereIn('type', ['payment', 'refund']);
    }

    public function scopeDebits($query)
    {
        return $query->whereIn('type', ['payout', 'fee']);
    }

    // Accessors
    public function getFormattedAmountAttribute()
    {
        $sign = $this->isCredit() ? '+' : '-';
        return $sign . 'R' . number_format(abs($this->amount), 2);
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
            'cancelled' => 'text-gray-600 bg-gray-100',
            default => 'text-gray-600 bg-gray-100',
        };
    }

    public function getTypeColorAttribute()
    {
        return match($this->type) {
            'payment' => 'text-green-600',
            'refund' => 'text-blue-600',
            'payout' => 'text-purple-600',
            'fee' => 'text-red-600',
            'adjustment' => 'text-yellow-600',
            default => 'text-gray-600',
        };
    }

    // Helper methods
    public function isCredit(): bool
    {
        return in_array($this->type, ['payment', 'refund', 'adjustment']) && $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return in_array($this->type, ['payout', 'fee']) || $this->amount < 0;
    }

    // Static methods
    public static function generateReference(): string
    {
        return 'TXN-' . strtoupper(uniqid());
    }

    public static function getBalanceForUser($userId)
    {
        $credits = static::forUser($userId)->completed()->credits()->sum('amount');
        $debits = static::forUser($userId)->completed()->debits()->sum('amount');
        
        return $credits - abs($debits);
    }
}
