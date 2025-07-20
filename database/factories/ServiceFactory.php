<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $title = fake()->sentence(3);
        
        return [
            'user_id' => User::factory(),
            'category_id' => ServiceCategory::factory(),
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title),
            'description' => fake()->paragraphs(3, true),
            'short_description' => fake()->sentence(15),
            'price_type' => fake()->randomElement(['fixed', 'hourly', 'negotiable']),
            'base_price' => fake()->randomFloat(2, 50, 500),
            'max_price' => null,
            'currency' => 'ZAR',
            'location_type' => fake()->randomElement(['client_location', 'service_location', 'online']),
            'latitude' => fake()->latitude(-35, -25), // South Africa region
            'longitude' => fake()->longitude(16, 33), // South Africa region
            'address' => fake()->address(),
            'tags' => json_encode(fake()->words(3)),
            'instant_booking' => fake()->boolean(30),
            'is_featured' => fake()->boolean(10),
            'status' => 'active',
            'view_count' => fake()->numberBetween(0, 1000),
            'booking_count' => fake()->numberBetween(0, 50),
            'favorite_count' => fake()->numberBetween(0, 100),
            'average_rating' => fake()->randomFloat(1, 3.0, 5.0),
            'review_count' => fake()->numberBetween(0, 50),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    public function withInstantBooking(): static
    {
        return $this->state(fn (array $attributes) => [
            'instant_booking' => true,
        ]);
    }

    public function fixedPrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_type' => 'fixed',
            'base_price' => fake()->randomFloat(2, 100, 300),
        ]);
    }

    public function hourlyPrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_type' => 'hourly',
            'base_price' => fake()->randomFloat(2, 50, 150),
        ]);
    }

    public function negotiablePrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_type' => 'negotiable',
            'base_price' => fake()->randomFloat(2, 200, 800),
            'max_price' => fake()->randomFloat(2, 800, 1500),
        ]);
    }
}