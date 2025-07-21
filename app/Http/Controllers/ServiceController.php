<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\ServiceCollection;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Services\ServiceBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(
        private ServiceBusinessService $serviceBusinessService
    ) {}

    /**
     * Display a listing of services with advanced filtering.
     */
    public function index(Request $request): ServiceCollection
    {
        $perPage = min($request->integer('per_page', 12), 50); // Max 50 items
        
        $filters = $request->only([
            'category', 'price_min', 'price_max', 'latitude', 'longitude',
            'radius', 'min_rating', 'location_type', 'featured', 'instant_booking',
            'tags', 'sort', 'page'
        ]);

        $services = $this->serviceBusinessService->getServices($filters, $perPage);

        return new ServiceCollection($services);
    }

    /**
     * Display services for the authenticated user (vendor view).
     */
    public function myServices(Request $request): ServiceCollection
    {
        $perPage = min($request->integer('per_page', 20), 50);
        
        $filters = $request->only(['status']);
        
        $services = $this->serviceBusinessService->getUserServices(
            auth()->id(),
            $filters,
            $perPage
        );

        return new ServiceCollection($services);
    }

    /**
     * Store a newly created service.
     */
    public function store(StoreServiceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $validated['user_id'] = auth()->id();

            $images = $request->hasFile('images') ? $request->file('images') : null;
            
            $service = $this->serviceBusinessService->createService($validated, $images);

            return response()->json([
                'message' => 'Service created successfully',
                'service' => new ServiceResource($service->load(['category', 'serviceImages']))
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified service by full slug (user-slug/service-slug).
     */
    public function show(string $userSlug, string $serviceSlug): ServiceResource
    {
        $service = $this->serviceBusinessService->findServiceBySlug($userSlug, $serviceSlug);

        if (!$service) {
            abort(404, 'Service not found');
        }

        return new ServiceResource($service);
    }

    /**
     * Display the specified service by ID (for dashboard/admin use).
     */
    public function showById(string $id): ServiceResource
    {
        $service = $this->serviceBusinessService->findServiceById($id);

        if (!$service) {
            abort(404, 'Service not found');
        }

        return new ServiceResource($service);
    }

    /**
     * Update the specified service.
     */
    public function update(UpdateServiceRequest $request, string $id): JsonResponse
    {
        try {
            $service = $this->serviceBusinessService->findServiceById($id);

            if (!$service) {
                return response()->json([
                    'message' => 'Service not found'
                ], 404);
            }

            // Check if service belongs to current user
            if ($service->user_id !== auth()->id()) {
                return response()->json([
                    'message' => 'Unauthorized to update this service'
                ], 403);
            }

            $validated = $request->validated();
            $images = $request->hasFile('images') ? $request->file('images') : null;
            
            $updatedService = $this->serviceBusinessService->updateService($service, $validated, $images);

            return response()->json([
                'message' => 'Service updated successfully',
                'service' => new ServiceResource($updatedService->load(['category', 'serviceImages']))
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified service.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $service = $this->serviceBusinessService->findServiceById($id);

            if (!$service) {
                return response()->json([
                    'message' => 'Service not found'
                ], 404);
            }

            // Check if service belongs to current user
            if ($service->user_id !== auth()->id()) {
                return response()->json([
                    'message' => 'Unauthorized to delete this service'
                ], 403);
            }

            $this->serviceBusinessService->deleteService($service);

            return response()->json([
                'message' => 'Service deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service analytics for vendor.
     */
    public function analytics(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        $analytics = $this->serviceBusinessService->getServiceAnalytics($service);

        return response()->json($analytics);
    }

    /**
     * Search services using full-text search.
     */
    public function search(Request $request): ServiceCollection
    {
        $query = $request->string('q', '');
        $perPage = min($request->integer('per_page', 12), 50);

        $services = $this->serviceBusinessService->searchServices($query, $perPage);

        return new ServiceCollection($services);
    }

    /**
     * Get all service categories.
     */
    public function categories(): JsonResponse
    {
        $categories = $this->serviceBusinessService->getCategories();

        return response()->json($categories);
    }

    /**
     * Get available slots for a specific service.
     */
    public function availableSlots(Request $request, string $userSlug, string $serviceSlug): JsonResponse
    {
        $request->validate([
            'start_date' => 'sometimes|date|after_or_equal:today',
            'end_date' => 'sometimes|date|after:start_date',
            'duration' => 'sometimes|integer|min:15|max:480'
        ]);

        try {
            $service = $this->serviceBusinessService->findServiceBySlug($userSlug, $serviceSlug);

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            // Default to next 7 days if no date range provided
            $startDate = $request->get('start_date', now()->toDateString());
            $endDate = $request->get('end_date', now()->addDays(7)->toDateString());
            $duration = $request->get('duration', $service->duration_minutes ?? 60);

            $availableSlots = $this->serviceBusinessService->getAvailableSlots(
                $service,
                $startDate,
                $endDate,
                $duration
            );

            return response()->json([
                'success' => true,
                'data' => $availableSlots,
                'meta' => [
                    'service_id' => $service->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'duration_minutes' => $duration,
                    'total_slots' => count($availableSlots)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available slots',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}