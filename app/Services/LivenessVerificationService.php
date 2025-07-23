<?php

namespace App\Services;

use App\Models\VendorVerification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LivenessVerificationService
{
    private const LIVENESS_THRESHOLD = 0.8;
    private const FACE_MATCH_THRESHOLD = 0.85;

    /**
     * Process liveness photo and verify against identity document.
     */
    public function processLivenessPhoto(VendorVerification $verification): bool
    {
        try {
            // Get identity document photo for comparison
            $identityDocument = $verification->documents()
                ->identityDocuments()
                ->verified()
                ->first();

            if (!$identityDocument) {
                throw new \InvalidArgumentException('No verified identity document found for comparison.');
            }

            // Perform liveness detection
            $livenessResults = $this->detectLiveness($verification);
            
            // Perform face matching
            $faceMatchResults = $this->compareFaces($verification, $identityDocument);

            // Combine results
            $verificationData = [
                'liveness_score' => $livenessResults['liveness_score'],
                'face_match_score' => $faceMatchResults['similarity_score'],
                'liveness_checks' => $livenessResults['checks'],
                'face_match_checks' => $faceMatchResults['checks'],
                'overall_score' => ($livenessResults['liveness_score'] + $faceMatchResults['similarity_score']) / 2,
                'verification_timestamp' => now()->toISOString(),
                'processor_service' => 'internal_liveness',
            ];

            $verification->update([
                'liveness_verification_data' => $verificationData,
                'liveness_verification_reference' => uniqid('liveness_'),
            ]);

            // Check if verification passed
            if ($this->passedLivenessVerification($verificationData)) {
                $this->markLivenessAsVerified($verification);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Liveness verification failed', [
                'verification_id' => $verification->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Detect liveness in the uploaded photo.
     */
    private function detectLiveness(VendorVerification $verification): array
    {
        // Mock liveness detection
        // In production, integrate with services like:
        // - Amazon Rekognition
        // - Microsoft Face API
        // - Google Cloud Vision AI
        // - FaceX or similar liveness detection services

        $livenessScore = 0.92; // Mock high liveness score

        return [
            'liveness_score' => $livenessScore,
            'checks' => [
                'eye_blink_detected' => true,
                'head_movement_detected' => true,
                'face_angle_appropriate' => true,
                'lighting_sufficient' => true,
                'image_quality_good' => true,
                'single_face_detected' => true,
                'face_size_appropriate' => true,
            ],
            'confidence_level' => 'high',
            'processing_time_ms' => 1250,
        ];
    }

    /**
     * Compare liveness photo with identity document photo.
     */
    private function compareFaces(VendorVerification $verification, $identityDocument): array
    {
        // Mock face comparison
        // In production, use actual face comparison services

        $similarityScore = 0.89; // Mock high similarity score

        return [
            'similarity_score' => $similarityScore,
            'checks' => [
                'faces_aligned' => true,
                'facial_features_match' => true,
                'age_consistency' => true,
                'gender_consistency' => true,
                'ethnicity_consistency' => true,
            ],
            'confidence_level' => 'high',
            'comparison_method' => 'deep_face_embedding',
            'processing_time_ms' => 850,
        ];
    }

    /**
     * Check if liveness verification passed.
     */
    private function passedLivenessVerification(array $verificationData): bool
    {
        return $verificationData['liveness_score'] >= self::LIVENESS_THRESHOLD &&
               $verificationData['face_match_score'] >= self::FACE_MATCH_THRESHOLD;
    }

    /**
     * Mark liveness as verified.
     */
    private function markLivenessAsVerified(VendorVerification $verification): void
    {
        $verification->update([
            'liveness_verified_at' => now(),
        ]);

        $verification->user->update(['liveness_verified' => true]);
        $verification->updateStatusBasedOnProgress();
    }

    /**
     * Integration examples for production services.
     * Uncomment and configure as needed.
     */
    
    /*
    private function verifyWithAWSRekognition(VendorVerification $verification): array
    {
        $client = new \Aws\Rekognition\RekognitionClient([
            'version' => 'latest',
            'region' => config('aws.default_region'),
            'credentials' => [
                'key' => config('aws.credentials.key'),
                'secret' => config('aws.credentials.secret'),
            ],
        ]);

        $livenessPhoto = Storage::disk('private')->get($verification->liveness_photo_path);

        $result = $client->detectFaces([
            'Image' => [
                'Bytes' => $livenessPhoto,
            ],
            'Attributes' => ['ALL'],
        ]);

        return $result->toArray();
    }

    private function verifyWithMicrosoftFaceAPI(VendorVerification $verification): array
    {
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => config('services.microsoft.face_api_key'),
            'Content-Type' => 'application/octet-stream',
        ])->post('https://your-region.api.cognitive.microsoft.com/face/v1.0/detect', [
            'body' => Storage::disk('private')->get($verification->liveness_photo_path),
            'query' => [
                'returnFaceId' => 'true',
                'returnFaceLandmarks' => 'false',
                'returnFaceAttributes' => 'age,gender,smile,facialHair,glasses,emotion,hair,makeup,accessories,blur,exposure,noise',
            ],
        ]);

        return $response->json();
    }

    private function compareWithFaceX(string $photo1Path, string $photo2Path): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.facex.api_key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.facex.io/v1/compare', [
            'image1' => base64_encode(Storage::disk('private')->get($photo1Path)),
            'image2' => base64_encode(Storage::disk('private')->get($photo2Path)),
        ]);

        return $response->json();
    }
    */
}