<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ServiceImage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'service_id',
        'original_filename',
        'original_path',
        'mime_type',
        'file_size',
        'width',
        'height',
        'thumbnails',
        'cdn_url',
        'webp_path',
        'avif_path',
        'alt_text',
        'description',
        'sort_order',
        'is_primary',
        'processing_status',
        'processing_metadata',
        'blurhash',
        'color_palette',
    ];

    protected $casts = [
        'thumbnails' => 'array',
        'processing_metadata' => 'array',
        'color_palette' => 'array',
        'is_primary' => 'boolean',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = [
        'url',
        'thumbnail_urls',
    ];

    /**
     * Get the service this image belongs to.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the full URL for the original image.
     */
    public function getUrlAttribute(): string
    {
        if ($this->cdn_url) {
            return $this->cdn_url;
        }

        return Storage::disk('public')->url($this->original_path);
    }

    /**
     * Get URLs for all thumbnail sizes.
     */
    public function getThumbnailUrlsAttribute(): array
    {
        $urls = [];
        
        if ($this->thumbnails) {
            foreach ($this->thumbnails as $size => $path) {
                $urls[$size] = Storage::disk('public')->url($path);
            }
        }

        return $urls;
    }

    /**
     * Get WebP URL if available.
     */
    public function getWebpUrlAttribute(): ?string
    {
        if ($this->webp_path) {
            return Storage::disk('public')->url($this->webp_path);
        }

        return null;
    }

    /**
     * Get AVIF URL if available.
     */
    public function getAvifUrlAttribute(): ?string
    {
        if ($this->avif_path) {
            return Storage::disk('public')->url($this->avif_path);
        }

        return null;
    }

    /**
     * Get responsive image srcset for different sizes.
     */
    public function getSrcsetAttribute(): string
    {
        $srcset = [];
        
        if ($this->thumbnails) {
            foreach ($this->thumbnails as $size => $path) {
                $width = $this->getSizeWidth($size);
                if ($width) {
                    $url = Storage::disk('public')->url($path);
                    $srcset[] = "{$url} {$width}w";
                }
            }
        }

        // Add original as fallback
        $originalUrl = $this->url;
        $srcset[] = "{$originalUrl} {$this->width}w";

        return implode(', ', $srcset);
    }

    /**
     * Check if image processing is complete.
     */
    public function isProcessed(): bool
    {
        return $this->processing_status === 'completed';
    }

    /**
     * Check if image processing failed.
     */
    public function processingFailed(): bool
    {
        return $this->processing_status === 'failed';
    }

    /**
     * Mark image as primary and unmark others.
     */
    public function markAsPrimary(): void
    {
        // Unmark other primary images for this service
        static::where('service_id', $this->service_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        // Mark this as primary
        $this->update(['is_primary' => true]);
    }

    /**
     * Get width for a thumbnail size.
     */
    private function getSizeWidth(string $size): ?int
    {
        $sizeMap = [
            'thumb' => 150,
            'small' => 300,
            'medium' => 600,
            'large' => 1200,
            'xlarge' => 1920,
        ];

        return $sizeMap[$size] ?? null;
    }

    /**
     * Scope to get only processed images.
     */
    public function scopeProcessed($query)
    {
        return $query->where('processing_status', 'completed');
    }

    /**
     * Scope to get images ordered by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Delete associated files when model is deleted.
     */
    public function delete()
    {
        // Delete original file
        if ($this->original_path && Storage::disk('public')->exists($this->original_path)) {
            Storage::disk('public')->delete($this->original_path);
        }

        // Delete WebP version
        if ($this->webp_path && Storage::disk('public')->exists($this->webp_path)) {
            Storage::disk('public')->delete($this->webp_path);
        }

        // Delete AVIF version
        if ($this->avif_path && Storage::disk('public')->exists($this->avif_path)) {
            Storage::disk('public')->delete($this->avif_path);
        }

        // Delete thumbnails
        if ($this->thumbnails) {
            foreach ($this->thumbnails as $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        }

        return parent::delete();
    }
}