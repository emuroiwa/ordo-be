<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FavoriteController extends Controller
{
    /**
     * Get user's favorites.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $favorites = Favorite::forUser($user->id)
                ->withService()
                ->orderBy('created_at', 'desc')
                ->get();

            // Format the favorites data
            $formattedFavorites = $favorites->map(function ($favorite) {
                $service = $favorite->service;
                
                // Get primary image
                $primaryImage = $service->serviceImages->first();
                
                return [
                    'id' => $favorite->id,
                    'user_id' => $favorite->user_id,
                    'service_id' => $favorite->service_id,
                    'notes' => $favorite->notes,
                    'created_at' => $favorite->created_at,
                    'updated_at' => $favorite->updated_at,
                    'service' => [
                        'id' => $service->id,
                        'title' => $service->title,
                        'description' => $service->description,
                        'category_id' => $service->category_id,
                        'category' => $service->category ? [
                            'id' => $service->category->id,
                            'name' => $service->category->name,
                            'slug' => $service->category->slug,
                        ] : null,
                        'base_price' => $service->base_price,
                        'currency' => $service->currency,
                        'location_type' => $service->location_type,
                        'address' => $service->address,
                        'latitude' => $service->latitude,
                        'longitude' => $service->longitude,
                        'status' => $service->status,
                        'is_featured' => $service->is_featured,
                        'instant_booking' => $service->instant_booking,
                        'slug' => $service->slug,
                        'full_slug' => $service->full_slug,
                        'primary_image' => $primaryImage ? [
                            'id' => $primaryImage->id,
                            'url' => $primaryImage->url,
                            'alt_text' => $primaryImage->alt_text,
                        ] : null,
                        'user' => $service->user ? [
                            'id' => $service->user->id,
                            'name' => $service->user->name,
                            'business_name' => $service->user->business_name,
                            'slug' => $service->user->slug,
                            'avatar_url' => $service->user->avatar_url,
                        ] : null,
                        'average_rating' => $service->average_rating,
                        'review_count' => $service->review_count,
                        'view_count' => $service->view_count,
                        'booking_count' => $service->booking_count,
                        'created_at' => $service->created_at,
                        'location_display' => $service->location_display,
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedFavorites,
                'count' => $formattedFavorites->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add service to favorites.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'service_id' => 'required|uuid|exists:services,id',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $serviceId = $request->input('service_id');

            // Check if already favorited
            $existingFavorite = Favorite::where('user_id', $user->id)
                ->where('service_id', $serviceId)
                ->first();

            if ($existingFavorite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service is already in your favorites'
                ], 409);
            }

            // Create favorite
            $favorite = Favorite::create([
                'user_id' => $user->id,
                'service_id' => $serviceId,
                'notes' => $request->input('notes')
            ]);

            // Load the favorite with service data
            $favorite->load(['service' => function ($q) {
                $q->with(['user', 'category', 'serviceImages' => function ($img) {
                    $img->where('is_primary', true);
                }]);
            }]);

            return response()->json([
                'success' => true,
                'message' => 'Service added to favorites',
                'favorite' => $favorite
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove service from favorites.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $favorite = Favorite::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$favorite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Favorite not found'
                ], 404);
            }

            $favorite->delete();

            return response()->json([
                'success' => true,
                'message' => 'Service removed from favorites'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle favorite status of a service.
     */
    public function toggle(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'service_id' => 'required|uuid|exists:services,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $serviceId = $request->input('service_id');

            $result = Favorite::toggleFavorite($user->id, $serviceId);

            return response()->json([
                'success' => true,
                'message' => $result['action'] === 'added' ? 'Service added to favorites' : 'Service removed from favorites',
                'action' => $result['action'],
                'favorited' => $result['favorited'],
                'favorite_id' => $result['favorite_id']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle favorite',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a service is favorited by the user.
     */
    public function check($serviceId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => true,
                    'favorited' => false,
                    'favorite_id' => null
                ]);
            }

            $favorited = Favorite::isFavorited($user->id, $serviceId);
            $favoriteId = $favorited ? Favorite::getFavoriteId($user->id, $serviceId) : null;

            return response()->json([
                'success' => true,
                'favorited' => $favorited,
                'favorite_id' => $favoriteId
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check favorite status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk remove favorites.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'favorite_ids' => 'required|array|min:1',
                'favorite_ids.*' => 'uuid|exists:favorites,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $favoriteIds = $request->input('favorite_ids');

            // Delete favorites that belong to the authenticated user
            $deletedCount = Favorite::whereIn('id', $favoriteIds)
                ->where('user_id', $user->id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "Removed {$deletedCount} favorite(s)",
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all user favorites.
     */
    public function clear(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $deletedCount = Favorite::where('user_id', $user->id)->delete();

            return response()->json([
                'success' => true,
                'message' => "Cleared {$deletedCount} favorite(s)",
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get favorites count for the user.
     */
    public function count(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $count = Favorite::getFavoritesCount($user->id);

            return response()->json([
                'success' => true,
                'count' => $count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get favorites count',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}