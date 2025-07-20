<?php

namespace App\Services;

use App\Models\VendorAvailability;
use App\Models\AvailabilitySlot;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AvailabilitySlotService
{
    /**
     * Generate availability slots from vendor availability.
     */
    public function generateSlotsFromVendorAvailability(VendorAvailability $vendorAvailability): array
    {
        return DB::transaction(function () use ($vendorAvailability) {
            $createdSlots = [];
            
            // Convert day name to number for AvailabilitySlot
            $dayOfWeek = $this->convertDayNameToNumber($vendorAvailability->day_of_week);
            
            // Get vendor's services
            $vendorServices = Service::where('user_id', $vendorAvailability->vendor_id)
                ->where('status', 'active')
                ->get();
            
            \Log::info('Generating availability slots', [
                'vendor_id' => $vendorAvailability->vendor_id,
                'day_of_week' => $vendorAvailability->day_of_week,
                'day_number' => $dayOfWeek,
                'services_count' => $vendorServices->count(),
                'availability_id' => $vendorAvailability->id
            ]);
            
            // If no services, create general slots
            if ($vendorServices->isEmpty()) {
                $slots = $this->createGeneralSlots($vendorAvailability, $dayOfWeek);
                $createdSlots = array_merge($createdSlots, $slots);
                \Log::info('Created general slots', ['count' => count($slots)]);
            } else {
                // Create slots for each service
                foreach ($vendorServices as $service) {
                    $slots = $this->createServiceSpecificSlots($vendorAvailability, $service, $dayOfWeek);
                    $createdSlots = array_merge($createdSlots, $slots);
                    \Log::info('Created service-specific slots', [
                        'service_id' => $service->id,
                        'service_title' => $service->title,
                        'slots_count' => count($slots)
                    ]);
                }
            }
            
            \Log::info('Total availability slots generated', ['total_count' => count($createdSlots)]);
            
            return $createdSlots;
        });
    }
    
    /**
     * Create general availability slots (not service-specific).
     */
    private function createGeneralSlots(VendorAvailability $vendorAvailability, int $dayOfWeek): array
    {
        $slots = [];
        $timeSlots = $this->generateTimeSlots($vendorAvailability);
        
        foreach ($timeSlots as $timeSlot) {
            $slot = AvailabilitySlot::create([
                'vendor_id' => $vendorAvailability->vendor_id,
                'service_id' => null, // General slot
                'day_of_week' => $dayOfWeek,
                'start_time' => $timeSlot['start'],
                'end_time' => $timeSlot['end'],
                'is_available' => true,
                'max_bookings' => 1,
            ]);
            
            $slots[] = $slot;
        }
        
        return $slots;
    }
    
    /**
     * Create service-specific availability slots.
     */
    private function createServiceSpecificSlots(VendorAvailability $vendorAvailability, Service $service, int $dayOfWeek): array
    {
        $slots = [];
        
        // Use service duration or default duration
        $serviceDuration = $service->duration_minutes ?? $vendorAvailability->default_duration;
        $timeSlots = $this->generateTimeSlots($vendorAvailability, $serviceDuration);
        
        foreach ($timeSlots as $timeSlot) {
            $slot = AvailabilitySlot::create([
                'vendor_id' => $vendorAvailability->vendor_id,
                'service_id' => $service->id,
                'day_of_week' => $dayOfWeek,
                'start_time' => $timeSlot['start'],
                'end_time' => $timeSlot['end'],
                'is_available' => true,
                'max_bookings' => 1,
            ]);
            
            $slots[] = $slot;
        }
        
        return $slots;
    }
    
    /**
     * Generate time slots based on vendor availability rules.
     */
    private function generateTimeSlots(VendorAvailability $vendorAvailability, ?int $serviceDuration = null): array
    {
        $slots = [];
        $duration = $serviceDuration ?? $vendorAvailability->default_duration;
        $bufferTime = $vendorAvailability->buffer_time;
        $intervalMinutes = $duration + $bufferTime;
        
        $startTime = Carbon::createFromTimeString($vendorAvailability->start_time);
        $endTime = Carbon::createFromTimeString($vendorAvailability->end_time);
        $breakTimes = $vendorAvailability->break_times ?? [];
        
        $currentTime = $startTime->copy();
        
        while ($currentTime->copy()->addMinutes($duration) <= $endTime) {
            $slotEnd = $currentTime->copy()->addMinutes($duration);
            
            // Check if this slot conflicts with any break times
            if (!$this->conflictsWithBreaks($currentTime, $slotEnd, $breakTimes)) {
                $slots[] = [
                    'start' => $currentTime->format('H:i:s'),
                    'end' => $slotEnd->format('H:i:s'),
                ];
            }
            
            $currentTime->addMinutes($intervalMinutes);
        }
        
        return $slots;
    }
    
    /**
     * Check if a time slot conflicts with break times.
     */
    private function conflictsWithBreaks(Carbon $slotStart, Carbon $slotEnd, array $breakTimes): bool
    {
        foreach ($breakTimes as $break) {
            $breakStart = Carbon::createFromTimeString($break['start']);
            $breakEnd = Carbon::createFromTimeString($break['end']);
            
            // Check for overlap
            if ($slotStart < $breakEnd && $slotEnd > $breakStart) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convert day name to numeric representation.
     */
    private function convertDayNameToNumber(string $dayName): int
    {
        $dayMap = [
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
        ];
        
        return $dayMap[strtolower($dayName)] ?? 1; // Default to Monday
    }
    
    /**
     * Remove existing availability slots for a vendor and day.
     */
    public function removeExistingSlotsForDay(string $vendorId, int $dayOfWeek): void
    {
        AvailabilitySlot::where('vendor_id', $vendorId)
            ->where('day_of_week', $dayOfWeek)
            ->delete();
    }
    
    /**
     * Regenerate all slots for a vendor availability.
     */
    public function regenerateSlots(VendorAvailability $vendorAvailability): array
    {
        $dayOfWeek = $this->convertDayNameToNumber($vendorAvailability->day_of_week);
        
        // Remove existing slots for this day
        $this->removeExistingSlotsForDay($vendorAvailability->vendor_id, $dayOfWeek);
        
        // Generate new slots
        return $this->generateSlotsFromVendorAvailability($vendorAvailability);
    }
}