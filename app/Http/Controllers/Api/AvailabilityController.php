<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendorAvailability;
use App\Services\AvailabilitySlotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AvailabilityController extends Controller
{
    protected AvailabilitySlotService $slotService;

    public function __construct(AvailabilitySlotService $slotService)
    {
        $this->slotService = $slotService;
    }
    /**
     * Display a listing of the vendor's availability.
     */
    public function index(Request $request): JsonResponse
    {
        $vendor = Auth::user();
        
        // Ensure user is a vendor
        if (!in_array('vendor', $vendor->roles ?? [])) {
            return response()->json([
                'success' => false,
                'message' => 'Only vendors can manage availability'
            ], 403);
        }

        $query = VendorAvailability::forVendor($vendor->id)->active();

        // Filter by date range if provided
        if ($request->has('date')) {
            $date = $request->get('date');
            $query->effectiveOn($date);
        }

        // Filter by day of week if provided
        if ($request->has('day_of_week')) {
            $query->forDay($request->get('day_of_week'));
        }

        $availabilities = $query->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $availabilities,
            'meta' => [
                'total' => $availabilities->count()
            ]
        ]);
    }

    /**
     * Store a newly created availability.
     */
    public function store(Request $request): JsonResponse
    {
        $vendor = Auth::user();
        
        // Ensure user is a vendor
        if (!in_array('vendor', $vendor->roles ?? [])) {
            return response()->json([
                'success' => false,
                'message' => 'Only vendors can manage availability'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'working_days' => 'required|array|min:1',
            'working_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'working_hours.start' => 'required|date_format:H:i',
            'working_hours.end' => 'required|date_format:H:i|after:working_hours.start',
            'break_times' => 'nullable|array',
            'break_times.*.start' => 'required_with:break_times|date_format:H:i',
            'break_times.*.end' => 'required_with:break_times|date_format:H:i|after:break_times.*.start',
            'default_duration' => 'required|integer|min:15|max:480',
            'buffer_time' => 'required|integer|min:0|max:120',
            'apply_to' => 'required|in:ongoing,dateRange',
            'date_range.start' => 'required_if:apply_to,dateRange|date|after_or_equal:today',
            'date_range.end' => 'required_if:apply_to,dateRange|date|after:date_range.start'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            $createdAvailabilities = [];
            $createdSlots = [];

            // Determine effective dates
            $effectiveFrom = $data['apply_to'] === 'dateRange' ? $data['date_range']['start'] : null;
            $effectiveUntil = $data['apply_to'] === 'dateRange' ? $data['date_range']['end'] : null;

            // Deactivate existing availabilities if applying to ongoing
            if ($data['apply_to'] === 'ongoing') {
                VendorAvailability::forVendor($vendor->id)
                    ->whereIn('day_of_week', $data['working_days'])
                    ->whereNull('effective_until')
                    ->update(['is_active' => false]);
            }

            // Create availability for each working day
            foreach ($data['working_days'] as $dayOfWeek) {
                $availabilityData = [
                    'vendor_id' => $vendor->id,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $data['working_hours']['start'],
                    'end_time' => $data['working_hours']['end'],
                    'break_times' => $data['break_times'] ?? null,
                    'default_duration' => $data['default_duration'],
                    'buffer_time' => $data['buffer_time'],
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $effectiveUntil,
                    'is_active' => true
                ];

                $availability = VendorAvailability::create($availabilityData);
                $createdAvailabilities[] = $availability;

                // Generate corresponding availability slots
                try {
                    $slots = $this->slotService->generateSlotsFromVendorAvailability($availability);
                    $createdSlots = array_merge($createdSlots, $slots);
                } catch (\Exception $slotError) {
                    // Log slot generation error but don't fail the entire operation
                    \Log::warning('Failed to generate availability slots for vendor availability: ' . $availability->id, [
                        'error' => $slotError->getMessage(),
                        'vendor_id' => $vendor->id,
                        'day_of_week' => $dayOfWeek
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Availability set successfully',
                'data' => [
                    'availabilities' => $createdAvailabilities,
                    'slots' => $createdSlots,
                    'slots_count' => count($createdSlots)
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified availability.
     */
    public function show(VendorAvailability $availability): JsonResponse
    {
        $vendor = Auth::user();
        
        // Ensure availability belongs to the authenticated vendor
        if ($availability->vendor_id !== $vendor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Availability not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $availability
        ]);
    }

    /**
     * Update the specified availability.
     */
    public function update(Request $request, VendorAvailability $availability): JsonResponse
    {
        $vendor = Auth::user();
        
        // Ensure availability belongs to the authenticated vendor
        if ($availability->vendor_id !== $vendor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Availability not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'break_times' => 'nullable|array',
            'break_times.*.start' => 'required_with:break_times|date_format:H:i',
            'break_times.*.end' => 'required_with:break_times|date_format:H:i|after:break_times.*.start',
            'default_duration' => 'sometimes|integer|min:15|max:480',
            'buffer_time' => 'sometimes|integer|min:0|max:120',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $availability->update($validator->validated());
            $updatedAvailability = $availability->fresh();

            // Regenerate availability slots if scheduling-related fields were updated
            $regeneratedSlots = [];
            $schedulingFields = ['start_time', 'end_time', 'break_times', 'default_duration', 'buffer_time'];
            if (array_intersect_key($validator->validated(), array_flip($schedulingFields))) {
                try {
                    $regeneratedSlots = $this->slotService->regenerateSlots($updatedAvailability);
                } catch (\Exception $slotError) {
                    Log::warning('Failed to regenerate availability slots after update: ' . $availability->id, [
                        'error' => $slotError->getMessage(),
                        'vendor_id' => $vendor->id
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Availability updated successfully',
                'data' => [
                    'availability' => $updatedAvailability,
                    'regenerated_slots' => $regeneratedSlots,
                    'slots_count' => count($regeneratedSlots)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified availability.
     */
    public function destroy(VendorAvailability $availability): JsonResponse
    {
        $vendor = Auth::user();
        
        // Ensure availability belongs to the authenticated vendor
        if ($availability->vendor_id !== $vendor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Availability not found'
            ], 404);
        }

        try {
            $availability->delete();

            return response()->json([
                'success' => true,
                'message' => 'Availability deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available time slots for a specific date.
     */
    public function getTimeSlots(Request $request): JsonResponse
    {
        $vendor = Auth::user();
        
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
            'service_id' => 'sometimes|exists:services,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->get('date');
        $availability = VendorAvailability::getVendorAvailabilityForDate($vendor->id, $date);

        if (!$availability) {
            return response()->json([
                'success' => true,
                'message' => 'No availability found for this date',
                'data' => []
            ]);
        }

        try {
            $timeSlots = $availability->getAvailableTimeSlots($date);

            // TODO: Filter out already booked time slots
            // This would require checking against existing bookings

            return response()->json([
                'success' => true,
                'data' => $timeSlots,
                'meta' => [
                    'date' => $date,
                    'day_of_week' => $availability->day_of_week,
                    'working_hours' => [
                        'start' => $availability->start_time,
                        'end' => $availability->end_time
                    ],
                    'break_times' => $availability->break_times,
                    'total_slots' => count($timeSlots)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get time slots',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendor's weekly availability overview.
     */
    public function getWeeklyOverview(Request $request): JsonResponse
    {
        $vendor = Auth::user();
        
        $validator = Validator::make($request->all(), [
            'start_date' => 'sometimes|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $startDate = $request->get('start_date', now()->startOfWeek());

        try {
            $weeklyAvailability = VendorAvailability::getVendorAvailabilityForWeek($vendor->id, $startDate);

            return response()->json([
                'success' => true,
                'data' => $weeklyAvailability,
                'meta' => [
                    'week_start' => Carbon::parse($startDate)->startOfWeek()->toDateString(),
                    'week_end' => Carbon::parse($startDate)->endOfWeek()->toDateString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get weekly overview',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update availability (deactivate/activate multiple days).
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $vendor = Auth::user();
        
        $validator = Validator::make($request->all(), [
            'availability_ids' => 'required|array|min:1',
            'availability_ids.*' => 'exists:vendor_availabilities,id',
            'action' => 'required|in:activate,deactivate,delete',
            'data' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $availabilityIds = $request->get('availability_ids');
            $action = $request->get('action');
            
            // Ensure all availabilities belong to the vendor
            $availabilities = VendorAvailability::whereIn('id', $availabilityIds)
                ->forVendor($vendor->id)
                ->get();

            if ($availabilities->count() !== count($availabilityIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some availabilities not found or do not belong to you'
                ], 404);
            }

            switch ($action) {
                case 'activate':
                    VendorAvailability::whereIn('id', $availabilityIds)->update(['is_active' => true]);
                    break;
                case 'deactivate':
                    VendorAvailability::whereIn('id', $availabilityIds)->update(['is_active' => false]);
                    break;
                case 'delete':
                    VendorAvailability::whereIn('id', $availabilityIds)->delete();
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => ucfirst($action) . ' completed successfully',
                'data' => [
                    'affected_count' => $availabilities->count(),
                    'action' => $action
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}