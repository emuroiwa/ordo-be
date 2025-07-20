<?php

namespace App\Jobs;

use App\Models\ServiceImage;
use App\Services\ImageProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessServiceImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public int $backoff = 30; // 30 seconds between retries

    public function __construct(
        private ServiceImage $serviceImage,
        private string $tempFilePath,
        private string $basePath,
        private string $filename
    ) {
        // Store uploaded file temporarily for async processing
        $this->tempFilePath = $tempFilePath;
    }

    /**
     * Execute the job.
     */
    public function handle(ImageProcessingService $imageProcessor): void
    {
        try {
            Log::info("Starting image processing for ServiceImage: {$this->serviceImage->id}");

            // Verify temp file exists
            if (!Storage::disk('local')->exists($this->tempFilePath)) {
                throw new \Exception("Temporary file not found: {$this->tempFilePath}");
            }

            // Create UploadedFile instance from stored temp file
            $tempPath = Storage::disk('local')->path($this->tempFilePath);
            $uploadedFile = new UploadedFile(
                $tempPath,
                $this->serviceImage->original_filename,
                $this->serviceImage->mime_type,
                null,
                true // Mark as test file to avoid validation errors
            );

            // Process the image
            $imageProcessor->processImageSync(
                $this->serviceImage,
                $uploadedFile,
                $this->basePath,
                $this->filename
            );

            Log::info("Successfully processed image for ServiceImage: {$this->serviceImage->id}");

            // Clean up temp file
            Storage::disk('local')->delete($this->tempFilePath);

        } catch (\Exception $e) {
            Log::error("Failed to process image for ServiceImage: {$this->serviceImage->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update status to failed
            $this->serviceImage->update([
                'processing_status' => 'failed',
                'processing_metadata' => [
                    'error' => $e->getMessage(),
                    'failed_at' => now(),
                    'attempt' => $this->attempts(),
                ],
            ]);

            // Clean up temp file
            if (Storage::disk('local')->exists($this->tempFilePath)) {
                Storage::disk('local')->delete($this->tempFilePath);
            }

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Image processing job failed permanently for ServiceImage: {$this->serviceImage->id}", [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Update status to failed
        $this->serviceImage->update([
            'processing_status' => 'failed',
            'processing_metadata' => [
                'error' => $exception->getMessage(),
                'failed_permanently_at' => now(),
                'total_attempts' => $this->attempts(),
            ],
        ]);

        // Clean up temp file
        if (Storage::disk('local')->exists($this->tempFilePath)) {
            Storage::disk('local')->delete($this->tempFilePath);
        }
    }

    /**
     * Prepare job for queue by storing uploaded file temporarily.
     */
    public static function createFromUploadedFile(
        ServiceImage $serviceImage,
        UploadedFile $file,
        string $basePath,
        string $filename
    ): self {
        // Store file temporarily with unique name
        $tempPath = 'temp/uploads/' . uniqid('img_', true) . '_' . $file->getClientOriginalName();
        Storage::disk('local')->put($tempPath, $file->getContent());

        return new self($serviceImage, $tempPath, $basePath, $filename);
    }
}