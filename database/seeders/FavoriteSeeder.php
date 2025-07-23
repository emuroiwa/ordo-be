<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Service;
use App\Models\Favorite;
use Illuminate\Database\Seeder;

class FavoriteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get customer users (users with customer role)
        $customers = User::whereJsonContains('roles', 'customer')->get();
        
        // Get active services
        $services = Service::where('status', 'active')->get();
        
        if ($customers->isEmpty() || $services->isEmpty()) {
            $this->command->info('No customers or services found. Skipping favorite seeding.');
            return;
        }

        foreach ($customers as $customer) {
            // Each customer will favorite 2-5 random services
            $favoriteCount = rand(2, 5);
            $randomServices = $services->random(min($favoriteCount, $services->count()));
            
            foreach ($randomServices as $service) {
                // Check if favorite already exists to avoid duplicate key error
                $existingFavorite = Favorite::where('user_id', $customer->id)
                    ->where('service_id', $service->id)
                    ->first();
                    
                if (!$existingFavorite) {
                    Favorite::create([
                        'user_id' => $customer->id,
                        'service_id' => $service->id,
                        'notes' => $this->getRandomNote(),
                        'metadata' => [
                            'added_from' => ['search', 'recommendation', 'browsing', 'shared'][array_rand(['search', 'recommendation', 'browsing', 'shared'])],
                            'source_page' => ['search_results', 'service_detail', 'category_page', 'recommendations'][array_rand(['search_results', 'service_detail', 'category_page', 'recommendations'])],
                            'position_in_list' => rand(1, 10)
                        ],
                        'created_at' => now()->subDays(rand(1, 30)), // Favorites from last 30 days
                    ]);
                }
            }
        }

        $this->command->info('Favorites seeded successfully.');
    }

    /**
     * Get a random note for favorites.
     */
    private function getRandomNote(): ?string
    {
        $notes = [
            null, // 50% chance of no note
            null,
            'Looks interesting, want to try later',
            'Recommended by a friend',
            'Great reviews and pricing',
            'Perfect for my upcoming event',
            'Need to book this for next month',
            'Love the location and service details',
            'Want to compare with other options',
            'Saving for when I have more time',
            'This provider seems very professional',
            'Good value for money',
        ];

        return $notes[array_rand($notes)];
    }
}