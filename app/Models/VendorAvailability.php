<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class VendorAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'day_of_week',
        'start_time',
        'end_time',
        'break_times',
        'default_duration',
        'buffer_time',
        'effective_from',
        'effective_until',
        'is_active'
    ];

    protected $casts = [
        'break_times' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
        'default_duration' => 'integer',
        'buffer_time' => 'integer'
    ];

    // Relationships
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForDay($query, $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    public function scopeEffectiveOn($query, $date)
    {
        $date = Carbon::parse($date)->toDateString();
        
        return $query->where(function ($q) use ($date) {
            $q->where(function ($subQ) use ($date) {
                // No effective_from date or effective_from is before/equal to target date
                $subQ->whereNull('effective_from')
                     ->orWhere('effective_from', '<=', $date);
            })->where(function ($subQ) use ($date) {
                // No effective_until date or effective_until is after/equal to target date
                $subQ->whereNull('effective_until')
                     ->orWhere('effective_until', '>=', $date);
            });
        });
    }

    // Methods
    public function isEffectiveOn($date): bool
    {
        $date = Carbon::parse($date);
        
        $effectiveFrom = $this->effective_from ? Carbon::parse($this->effective_from) : null;
        $effectiveUntil = $this->effective_until ? Carbon::parse($this->effective_until) : null;
        
        if ($effectiveFrom && $date->lt($effectiveFrom)) {
            return false;
        }
        
        if ($effectiveUntil && $date->gt($effectiveUntil)) {
            return false;
        }
        
        return true;
    }

    public function getAvailableTimeSlots($date): array
    {
        $slots = [];
        $startTime = Carbon::parse($this->start_time);
        $endTime = Carbon::parse($this->end_time);
        $duration = $this->default_duration;
        $buffer = $this->buffer_time;
        
        $currentSlot = $startTime->copy();
        
        while ($currentSlot->addMinutes($duration)->lte($endTime)) {
            $slotStart = $currentSlot->copy()->subMinutes($duration);
            $slotEnd = $currentSlot->copy();
            
            // Check if slot conflicts with break times
            if (!$this->isSlotInBreakTime($slotStart, $slotEnd)) {
                $slots[] = [
                    'start' => $slotStart->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'duration' => $duration
                ];
            }
            
            // Add buffer time
            $currentSlot->addMinutes($buffer);
        }
        
        return $slots;
    }

    private function isSlotInBreakTime($slotStart, $slotEnd): bool
    {
        if (!$this->break_times) {
            return false;
        }
        
        foreach ($this->break_times as $breakTime) {
            $breakStart = Carbon::parse($breakTime['start']);
            $breakEnd = Carbon::parse($breakTime['end']);
            
            // Check if slot overlaps with break time
            if ($slotStart->lt($breakEnd) && $slotEnd->gt($breakStart)) {
                return true;
            }
        }
        
        return false;
    }

    public function toCalendarEvent($date): array
    {
        return [
            'id' => $this->id,
            'title' => 'Available',
            'start' => Carbon::parse($date)->format('Y-m-d') . 'T' . $this->start_time,
            'end' => Carbon::parse($date)->format('Y-m-d') . 'T' . $this->end_time,
            'type' => 'availability',
            'backgroundColor' => '#10B981', // Green
            'borderColor' => '#059669',
            'classNames' => ['availability-slot']
        ];
    }

    // Static methods
    public static function getVendorAvailabilityForDate($vendorId, $date)
    {
        $dayOfWeek = Carbon::parse($date)->format('l'); // Full day name
        $dayOfWeek = strtolower($dayOfWeek);
        
        return static::active()
            ->forVendor($vendorId)
            ->forDay($dayOfWeek)
            ->effectiveOn($date)
            ->first();
    }

    public static function getVendorAvailabilityForWeek($vendorId, $startDate)
    {
        $availabilities = [];
        $start = Carbon::parse($startDate)->startOfWeek();
        
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $availability = static::getVendorAvailabilityForDate($vendorId, $date);
            
            if ($availability) {
                $availabilities[] = $availability->toCalendarEvent($date);
            }
        }
        
        return $availabilities;
    }
}