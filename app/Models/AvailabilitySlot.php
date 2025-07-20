<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AvailabilitySlot extends Model
{
    use HasUuids;

    protected $fillable = [
        'vendor_id',
        'service_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
        'max_bookings',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'is_available' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Scopes
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeForDay($query, int $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    public function scopeForVendor($query, string $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForService($query, string $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    /**
     * Accessors
     */
    public function getDayNameAttribute(): string
    {
        $days = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];

        return $days[$this->day_of_week] ?? 'Unknown';
    }

    public function getFormattedTimeSlotAttribute(): string
    {
        return Carbon::parse($this->start_time)->format('H:i') . ' - ' . 
               Carbon::parse($this->end_time)->format('H:i');
    }

    public function getDurationMinutesAttribute(): int
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);
        
        return $start->diffInMinutes($end);
    }

    /**
     * Business Logic Methods
     */
    public function isAvailableForDate(Carbon $date, int $durationMinutes = 60): bool
    {
        Log::info('Checking availability for slot on xxxxxxxxxxxxxx ' . $date->dayOfWeek . ' from ' . 
            $this->day_of_week . ' to ' . $this->end_time);
        // Check if the day matches
        if ($date->dayOfWeek !== $this->day_of_week) {
            return false;
        }

        // Check if slot is marked as available
        if (!$this->is_available) {
            return false;
        }

        Log::info('bbbbbbbbbbbbb ' . $date->format('Y-m-d') . 
            ' from ' . $durationMinutes . ' to ' . $this->duration_minutes);
        // Check if requested duration fits in this slot
        if ($durationMinutes > $this->duration_minutes) {
            return false;
        }

        // Check existing bookings for this slot on this date
        $existingBookings = Booking::where('vendor_id', $this->vendor_id)
            ->where('scheduled_at', '>=', $date->copy()->setTimeFromTimeString($this->start_time))
            ->where('scheduled_at', '<', $date->copy()->setTimeFromTimeString($this->end_time))
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();

        Log::info('Existing bookings for slot: ' . $existingBookings . ' with max bookings: ' . $this->max_bookings);
        return $existingBookings < $this->max_bookings;
    }

    public function getAvailableTimeSlots(Carbon $date, int $durationMinutes = 60): array
    {
        if (!$this->isAvailableForDate($date, $durationMinutes)) {
            return [];
        }

        $slots = [];
        $slotStart = Carbon::parse($this->start_time);
        $slotEnd = Carbon::parse($this->end_time);
        $interval = 30; // 30-minute intervals

        while ($slotStart->copy()->addMinutes($durationMinutes) <= $slotEnd) {
            $startTime = $date->copy()->setTimeFromTimeString($slotStart->format('H:i:s'));
            
            // Check if this specific time slot is available
            $conflictingBookings = Booking::where('vendor_id', $this->vendor_id)
                ->where('scheduled_at', '<=', $startTime)
                ->where('scheduled_at', '>', $startTime->copy()->subMinutes($durationMinutes))
                ->whereIn('status', ['pending', 'confirmed'])
                ->count();

            if ($conflictingBookings < $this->max_bookings) {
                $slots[] = [
                    'start_time' => $startTime->format('H:i'),
                    'end_time' => $startTime->copy()->addMinutes($durationMinutes)->format('H:i'),
                    'datetime' => $startTime->toISOString(),
                ];
            }

            $slotStart->addMinutes($interval);
        }

        return $slots;
    }
}
