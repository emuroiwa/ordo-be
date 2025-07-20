<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Home & Garden',
                'slug' => 'home-garden',
                'description' => 'Home improvement, maintenance, and garden services',
                'icon' => 'home',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 1,
                'meta_title' => 'Home & Garden Services',
                'meta_description' => 'Find trusted professionals for home improvement, maintenance, and garden services',
            ],
            [
                'name' => 'Beauty & Wellness',
                'slug' => 'beauty-wellness',
                'description' => 'Beauty treatments, wellness services, and personal care',
                'icon' => 'sparkles',
                'color' => '#EC4899',
                'is_active' => true,
                'sort_order' => 2,
                'meta_title' => 'Beauty & Wellness Services',
                'meta_description' => 'Book beauty treatments, wellness services, and personal care with certified professionals',
            ],
            [
                'name' => 'Automotive',
                'slug' => 'automotive',
                'description' => 'Car services, repairs, and maintenance',
                'icon' => 'truck',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 3,
                'meta_title' => 'Automotive Services',
                'meta_description' => 'Professional car services, repairs, and maintenance from trusted mechanics',
            ],
            [
                'name' => 'Technology',
                'slug' => 'technology',
                'description' => 'IT support, web development, and tech consulting',
                'icon' => 'computer-desktop',
                'color' => '#3B82F6',
                'is_active' => true,
                'sort_order' => 4,
                'meta_title' => 'Technology Services',
                'meta_description' => 'Expert IT support, web development, and technology consulting services',
            ],
            [
                'name' => 'Health & Fitness',
                'slug' => 'health-fitness',
                'description' => 'Personal training, nutrition, and health services',
                'icon' => 'heart',
                'color' => '#EF4444',
                'is_active' => true,
                'sort_order' => 5,
                'meta_title' => 'Health & Fitness Services',
                'meta_description' => 'Personal training, nutrition coaching, and health services from certified professionals',
            ],
            [
                'name' => 'Education & Tutoring',
                'slug' => 'education-tutoring',
                'description' => 'Academic tutoring, language learning, and skill development',
                'icon' => 'academic-cap',
                'color' => '#8B5CF6',
                'is_active' => true,
                'sort_order' => 6,
                'meta_title' => 'Education & Tutoring Services',
                'meta_description' => 'Find qualified tutors for academic subjects, language learning, and skill development',
            ],
            [
                'name' => 'Event Planning',
                'slug' => 'event-planning',
                'description' => 'Wedding planning, party organization, and event management',
                'icon' => 'calendar-days',
                'color' => '#F97316',
                'is_active' => true,
                'sort_order' => 7,
                'meta_title' => 'Event Planning Services',
                'meta_description' => 'Professional event planning for weddings, parties, and corporate events',
            ],
            [
                'name' => 'Pet Care',
                'slug' => 'pet-care',
                'description' => 'Pet grooming, walking, sitting, and veterinary services',
                'icon' => 'heart',
                'color' => '#06B6D4',
                'is_active' => true,
                'sort_order' => 8,
                'meta_title' => 'Pet Care Services',
                'meta_description' => 'Trusted pet care services including grooming, walking, sitting, and veterinary care',
            ],
            [
                'name' => 'Photography',
                'slug' => 'photography',
                'description' => 'Professional photography for events, portraits, and commercial use',
                'icon' => 'camera',
                'color' => '#84CC16',
                'is_active' => true,
                'sort_order' => 9,
                'meta_title' => 'Photography Services',
                'meta_description' => 'Professional photographers for weddings, events, portraits, and commercial photography',
            ],
            [
                'name' => 'Cleaning Services',
                'slug' => 'cleaning-services',
                'description' => 'House cleaning, office cleaning, and specialized cleaning services',
                'icon' => 'sparkles',
                'color' => '#14B8A6',
                'is_active' => true,
                'sort_order' => 10,
                'meta_title' => 'Cleaning Services',
                'meta_description' => 'Professional cleaning services for homes, offices, and specialized cleaning needs',
            ],
            [
                'name' => 'Legal & Financial',
                'slug' => 'legal-financial',
                'description' => 'Legal advice, accounting, and financial planning services',
                'icon' => 'scale',
                'color' => '#6366F1',
                'is_active' => true,
                'sort_order' => 11,
                'meta_title' => 'Legal & Financial Services',
                'meta_description' => 'Professional legal advice, accounting, and financial planning services',
            ],
            [
                'name' => 'Moving & Delivery',
                'slug' => 'moving-delivery',
                'description' => 'Moving services, delivery, and logistics',
                'icon' => 'truck',
                'color' => '#DC2626',
                'is_active' => true,
                'sort_order' => 12,
                'meta_title' => 'Moving & Delivery Services',
                'meta_description' => 'Reliable moving services, delivery, and logistics solutions',
            ],
            [
                'name' => 'Creative & Design',
                'slug' => 'creative-design',
                'description' => 'Graphic design, web design, and creative services',
                'icon' => 'paint-brush',
                'color' => '#BE185D',
                'is_active' => true,
                'sort_order' => 13,
                'meta_title' => 'Creative & Design Services',
                'meta_description' => 'Professional graphic design, web design, and creative services for your business',
            ],
            [
                'name' => 'Food & Catering',
                'slug' => 'food-catering',
                'description' => 'Catering services, meal prep, and food delivery',
                'icon' => 'cake',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 14,
                'meta_title' => 'Food & Catering Services',
                'meta_description' => 'Professional catering services, meal preparation, and food delivery options',
            ],
            [
                'name' => 'Business Services',
                'slug' => 'business-services',
                'description' => 'Consulting, marketing, and business support services',
                'icon' => 'briefcase',
                'color' => '#059669',
                'is_active' => true,
                'sort_order' => 15,
                'meta_title' => 'Business Services',
                'meta_description' => 'Professional business consulting, marketing, and support services to grow your business',
            ],
        ];

        foreach ($categories as $category) {
            ServiceCategory::create($category);
        }
    }
}