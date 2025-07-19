<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Notification extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'priority',
        'channel',
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
        'read_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the notifiable entity that the notification belongs to.
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Mark the notification as read.
     */
    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->forceFill(['read_at' => $this->freshTimestamp()])->save();
        }
    }

    /**
     * Mark the notification as unread.
     */
    public function markAsUnread(): void
    {
        if (!is_null($this->read_at)) {
            $this->forceFill(['read_at' => null])->save();
        }
    }

    /**
     * Determine if a notification has been read.
     */
    public function read(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Determine if a notification has not been read.
     */
    public function unread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Check if notification is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Scope to only include unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to only include read notifications.
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope to only include non-expired notifications.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to filter by priority.
     */
    public function scopePriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to filter by type.
     */
    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Get the notification's display title.
     */
    public function getTitle(): string
    {
        return $this->data['title'] ?? $this->getDefaultTitle();
    }

    /**
     * Get the notification's display message.
     */
    public function getMessage(): string
    {
        return $this->data['message'] ?? '';
    }

    /**
     * Get the notification's action URL.
     */
    public function getActionUrl(): ?string
    {
        return $this->metadata['action_url'] ?? null;
    }

    /**
     * Get the notification's icon.
     */
    public function getIcon(): string
    {
        return $this->metadata['icon'] ?? $this->getDefaultIcon();
    }

    /**
     * Get default title based on notification type.
     */
    private function getDefaultTitle(): string
    {
        return match($this->type) {
            'booking_confirmed' => 'Booking Confirmed',
            'booking_cancelled' => 'Booking Cancelled',
            'payment_received' => 'Payment Received',
            'payment_failed' => 'Payment Failed',
            'new_review' => 'New Review',
            'profile_updated' => 'Profile Updated',
            'welcome' => 'Welcome to ORDO',
            default => 'Notification',
        };
    }

    /**
     * Get default icon based on notification type.
     */
    private function getDefaultIcon(): string
    {
        return match($this->type) {
            'booking_confirmed' => 'check-circle',
            'booking_cancelled' => 'x-circle',
            'payment_received' => 'credit-card',
            'payment_failed' => 'exclamation-triangle',
            'new_review' => 'star',
            'profile_updated' => 'user',
            'welcome' => 'heart',
            default => 'bell',
        };
    }
}
