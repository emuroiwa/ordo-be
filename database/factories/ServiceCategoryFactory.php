<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Cleaning Services',
            'Home Repair',
            'Beauty & Wellness',
            'Tutoring',
            'Pet Services',
            'Event Planning',
            'Photography',
            'Web Development',
            'Graphic Design',
            'Fitness Training'
        ]);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'description' => fake()->sentence(10),
            'icon' => fake()->randomElement(['cleaning', 'repair', 'beauty', 'education', 'pets']),
            'color' => fake()->hexColor(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}