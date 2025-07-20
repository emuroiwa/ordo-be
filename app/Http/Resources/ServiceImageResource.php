<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'alt_text' => $this->alt_text,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_primary' => $this->is_primary,
            'processing_status' => $this->processing_status,
            'width' => $this->width,
            'height' => $this->height,
            'file_size' => $this->file_size,
            'blurhash' => $this->blurhash,
            'color_palette' => $this->color_palette,
            'url' => $this->url,
            'webp_url' => $this->webp_url,
            'avif_url' => $this->avif_url,
            'thumbnail_urls' => $this->thumbnail_urls,
            'srcset' => $this->srcset,
            'created_at' => $this->created_at,
        ];
    }
}