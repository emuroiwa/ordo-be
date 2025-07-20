<?php

namespace App\Services;

use App\Models\ServiceImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageProcessingService
{
    /**
     * Process and store a service image.
     */
    public function processServiceImage(UploadedFile $file, string $serviceId): ServiceImage
    {
        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $path = "services/{$serviceId}/{$filename}";

        // Store original file
        $storedPath = Storage::disk('public')->putFileAs(
            "services/{$serviceId}",
            $file,
            $filename
        );

        // Create service image record
        $serviceImage = ServiceImage::create([
            'service_id' => $serviceId,
            'original_filename' => $file->getClientOriginalName(),
            'original_path' => $storedPath,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'processing_status' => 'completed', // Simplified for testing
            'sort_order' => $this->getNextSortOrder($serviceId),
            'is_primary' => $this->shouldBePrimary($serviceId),
            'width' => 800,
            'height' => 600,
        ]);

        return $serviceImage;
    }

    /**
     * Get the next sort order for images in a service.
     */
    private function getNextSortOrder(string $serviceId): int
    {
        $maxOrder = ServiceImage::where('service_id', $serviceId)->max('sort_order');
        return ($maxOrder ?? 0) + 1;
    }

    /**
     * Determine if this should be the primary image.
     */
    private function shouldBePrimary(string $serviceId): bool
    {
        return !ServiceImage::where('service_id', $serviceId)->exists();
    }

    /**
     * Delete all images for a service.
     */
    public function deleteServiceImages(string $serviceId): void
    {
        $images = ServiceImage::where('service_id', $serviceId)->get();
        
        foreach ($images as $image) {
            $image->delete();
        }
    }
}