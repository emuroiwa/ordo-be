<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Review;
use App\Models\User;
use App\Models\Service;
use App\Models\Booking;
use Carbon\Carbon;

class ReviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get some users and services to create reviews for
        $customers = User::whereJsonContains('roles', 'customer')->take(10)->get();
        $services = Service::with('user')->take(20)->get();
        
        if ($customers->isEmpty() || $services->isEmpty()) {
            $this->command->info('No customers or services found. Skipping review seeding.');
            return;
        }
        
        $reviewTexts = [
            [
                'title' => 'Excellent service!',
                'comment' => 'The service was outstanding. Professional, punctual, and exceeded my expectations. Would definitely recommend to others.',
                'rating' => 5
            ],
            [
                'title' => 'Very satisfied',
                'comment' => 'Great quality work and friendly service. The provider was knowledgeable and completed everything on time.',
                'rating' => 5
            ],
            [
                'title' => 'Good experience',
                'comment' => 'Overall a positive experience. The service was good quality and reasonably priced.',
                'rating' => 4
            ],
            [
                'title' => 'Professional and reliable',
                'comment' => 'Very professional approach and reliable service. Clean work environment and attention to detail.',
                'rating' => 5
            ],
            [
                'title' => 'Decent service',
                'comment' => 'The service was okay, nothing special but got the job done. Could improve communication.',
                'rating' => 3
            ],
            [
                'title' => 'Fantastic results',
                'comment' => 'Amazing results! The provider really knew what they were doing and the final outcome was perfect.',
                'rating' => 5
            ],
            [
                'title' => 'Quick and efficient',
                'comment' => 'Fast service without compromising on quality. Very efficient and well-organized.',
                'rating' => 4
            ],
            [
                'title' => 'Not what I expected',
                'comment' => 'The service was below my expectations. There were some issues with timing and quality.',
                'rating' => 2
            ],
            [
                'title' => 'Highly recommend',
                'comment' => 'Exceptional service from start to finish. Professional, friendly, and great value for money.',
                'rating' => 5
            ],
            [
                'title' => 'Good value',
                'comment' => 'Fair pricing for the quality of service received. Would use again for future needs.',
                'rating' => 4
            ],
            [
                'title' => 'Amazing experience',
                'comment' => 'From booking to completion, everything was smooth and professional. Top-notch service!',
                'rating' => 5
            ],
            [
                'title' => 'Could be better',
                'comment' => 'The service was acceptable but there is definitely room for improvement in several areas.',
                'rating' => 3
            ],
            [
                'title' => 'Skilled and courteous',
                'comment' => 'Very skilled provider who was also courteous and respectful. Great communication throughout.',
                'rating' => 5
            ],
            [
                'title' => 'Satisfactory',
                'comment' => 'Met my basic expectations. Nothing extraordinary but a solid, reliable service.',
                'rating' => 3
            ],
            [
                'title' => 'Outstanding quality',
                'comment' => 'The quality of work was outstanding. Attention to detail was impressive and results were perfect.',
                'rating' => 5
            ]
        ];
        
        $responses = [
            'Thank you so much for your wonderful review! We\'re thrilled that you had such a positive experience.',
            'We really appreciate your feedback and are glad we could exceed your expectations!',
            'Thank you for choosing our service and for taking the time to leave this review.',
            'Your satisfaction is our priority. Thanks for the great review!',
            'We appreciate your feedback and will use it to continue improving our services.',
            'Thank you for the honest feedback. We\'ll work on the areas you mentioned.',
            'So happy to hear you loved the results! Thank you for the recommendation.',
            'Thank you for your business and for sharing your experience with others.',
        ];
        
        // Create reviews for the past 6 months
        foreach ($services as $service) {
            // Create 2-8 reviews per service
            $reviewCount = rand(2, 8);
            
            for ($i = 0; $i < $reviewCount; $i++) {
                $customer = $customers->random();
                $reviewData = $reviewTexts[array_rand($reviewTexts)];
                
                // Create review with random date in the past 6 months
                $createdAt = Carbon::now()->subDays(rand(1, 180));
                
                $review = Review::create([
                    'user_id' => $customer->id,
                    'service_id' => $service->id,
                    'rating' => $reviewData['rating'],
                    'title' => $reviewData['title'],
                    'comment' => $reviewData['comment'],
                    'is_verified' => rand(0, 100) < 70, // 70% chance of being verified
                    'is_featured' => rand(0, 100) < 10, // 10% chance of being featured
                    'helpful_count' => rand(0, 15),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                
                // Add vendor response to 60% of reviews
                if (rand(0, 100) < 60) {
                    $responseText = $responses[array_rand($responses)];
                    $responseDate = $createdAt->copy()->addDays(rand(1, 7));
                    
                    $review->update([
                        'vendor_response' => $responseText,
                        'vendor_response_at' => $responseDate,
                        'updated_at' => $responseDate,
                    ]);
                }
            }
        }
        
        $this->command->info('Reviews seeded successfully!');
    }
}
