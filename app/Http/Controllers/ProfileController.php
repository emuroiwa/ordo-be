<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function __construct()
    {
        // Middleware is now handled in routes/api.php
    }

    /**
     * Get the authenticated user's profile.
     */
    public function show(): UserResource
    {
        return new UserResource(auth()->user());
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            $avatarPath = $this->handleAvatarUpload($request->file('avatar'), $user);
            $validated['avatar'] = $avatarPath;
        }

        // Generate new slug if business_name or name changed
        if (isset($validated['business_name']) || isset($validated['name'])) {
            $user->fill($validated);
            $validated['slug'] = $user->generateSlug();
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => new UserResource($user->fresh())
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = auth()->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'errors' => ['current_password' => ['Current password is incorrect']]
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }

    /**
     * Upload and update user avatar.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // 5MB max
        ]);

        $user = auth()->user();
        $avatarPath = $this->handleAvatarUpload($request->file('avatar'), $user);

        $user->update(['avatar' => $avatarPath]);

        return response()->json([
            'message' => 'Avatar updated successfully',
            'avatar_url' => $user->fresh()->avatar_url
        ]);
    }

    /**
     * Delete user avatar.
     */
    public function deleteAvatar(): JsonResponse
    {
        $user = auth()->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return response()->json([
            'message' => 'Avatar deleted successfully',
            'avatar_url' => $user->fresh()->avatar_url
        ]);
    }

    /**
     * Get user statistics for profile dashboard.
     */
    public function stats(): JsonResponse
    {
        $user = auth()->user();

        $stats = [
            'services_count' => $user->isVendor() ? $user->services()->count() : 0,
            'active_services_count' => $user->isVendor() ? $user->services()->where('status', 'active')->count() : 0,
            'total_bookings' => 0, // Implement when booking system is ready
            'total_reviews' => 0, // Implement when review system is ready
            'average_rating' => 0.0, // Implement when review system is ready
            'member_since' => $user->created_at->format('F Y'),
            'profile_completion' => $this->calculateProfileCompletion($user),
        ];

        return response()->json($stats);
    }

    /**
     * Handle avatar file upload with optimization.
     */
    private function handleAvatarUpload($file, $user): string
    {
        // Delete old avatar if exists
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        // Generate unique filename
        $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = "avatars/{$filename}";

        // Store the file
        Storage::disk('public')->put($path, $this->optimizeImage($file));

        return $path;
    }

    /**
     * Optimize image for avatar (resize and compress).
     */
    private function optimizeImage($file): string
    {
        // Create image resource
        $image = imagecreatefromstring(file_get_contents($file->getPathname()));
        
        if (!$image) {
            return file_get_contents($file->getPathname());
        }

        // Get original dimensions
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        // Target size for avatar
        $targetSize = 300;

        // Calculate new dimensions (square crop)
        $size = min($originalWidth, $originalHeight);
        $x = ($originalWidth - $size) / 2;
        $y = ($originalHeight - $size) / 2;

        // Create new image
        $newImage = imagecreatetruecolor($targetSize, $targetSize);
        
        // Enable alpha blending for PNG
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);

        // Resample image
        imagecopyresampled(
            $newImage, $image,
            0, 0, $x, $y,
            $targetSize, $targetSize, $size, $size
        );

        // Output to string
        ob_start();
        imagejpeg($newImage, null, 85); // 85% quality
        $imageData = ob_get_contents();
        ob_end_clean();

        // Clean up memory
        imagedestroy($image);
        imagedestroy($newImage);

        return $imageData;
    }

    /**
     * Calculate profile completion percentage.
     */
    private function calculateProfileCompletion($user): int
    {
        $fields = [
            'name' => !empty($user->name),
            'email' => !empty($user->email),
            'phone' => !empty($user->phone),
            'business_name' => !empty($user->business_name),
            'service_category' => !empty($user->service_category),
            'avatar' => !empty($user->avatar),
        ];

        $completed = array_sum($fields);
        $total = count($fields);

        return round(($completed / $total) * 100);
    }
}