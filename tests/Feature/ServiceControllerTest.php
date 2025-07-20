<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;
    private User $customer;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->vendor = User::factory()->create([
            'roles' => ['vendor'],
            'business_name' => 'Test Business'
        ]);

        $this->customer = User::factory()->create([
            'roles' => ['customer']
        ]);

        // Create test category
        $this->category = ServiceCategory::factory()->create([
            'name' => 'Test Category',
            'slug' => 'test-category'
        ]);

        // Set up fake storage
        Storage::fake('public');
    }

    /** @test */
    public function unauthenticated_users_can_view_services_list()
    {
        // Create some test services
        Service::factory()->count(3)->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/v1/services');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'title',
                            'short_description',
                            'price_type',
                            'base_price',
                            'currency',
                            'category',
                            'user'
                        ]
                    ],
                    'meta' => [
                        'current_page',
                        'total',
                        'per_page'
                    ]
                ]);
    }

    /** @test */
    public function authenticated_vendor_can_create_service()
    {
        Sanctum::actingAs($this->vendor);

        $serviceData = [
            'title' => 'Test Service',
            'description' => 'This is a test service description',
            'short_description' => 'Short description',
            'category_id' => $this->category->id,
            'price_type' => 'fixed',
            'base_price' => 100.00,
            'currency' => 'ZAR',
            'location_type' => 'client_location',
            'instant_booking' => true
        ];

        $response = $this->postJson('/api/v1/services', $serviceData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'message',
                    'service' => [
                        'id',
                        'title',
                        'slug',
                        'description',
                        'category',
                        'user'
                    ]
                ]);

        $this->assertDatabaseHas('services', [
            'title' => 'Test Service',
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'price_type' => 'fixed',
            'base_price' => 100.00
        ]);
    }

    /** @test */
    public function vendor_can_create_service_with_images()
    {
        Sanctum::actingAs($this->vendor);

        $image1 = UploadedFile::fake()->image('service1.jpg', 800, 600)->size(1000);
        $image2 = UploadedFile::fake()->image('service2.jpg', 800, 600)->size(1000);

        $serviceData = [
            'title' => 'Service with Images',
            'description' => 'Service with test images',
            'short_description' => 'Short description',
            'category_id' => $this->category->id,
            'price_type' => 'fixed',
            'base_price' => 150.00,
            'currency' => 'ZAR',
            'location_type' => 'service_location',
            'instant_booking' => false,
            'images' => [$image1, $image2]
        ];

        $response = $this->post('/api/v1/services', $serviceData, [
            'Authorization' => 'Bearer ' . $this->vendor->createToken('test')->plainTextToken,
            'Accept' => 'application/json'
        ]);

        if ($response->status() !== 201) {
            dump($response->json());
        }

        $response->assertStatus(201);

        $this->assertDatabaseHas('services', [
            'title' => 'Service with Images',
            'user_id' => $this->vendor->id
        ]);
    }

    /** @test */
    public function unauthenticated_users_cannot_create_service()
    {
        $serviceData = [
            'title' => 'Unauthorized Service',
            'description' => 'This should fail',
            'category_id' => $this->category->id,
            'price_type' => 'fixed',
            'base_price' => 100.00,
            'currency' => 'ZAR',
            'location_type' => 'client_location'
        ];

        $response = $this->postJson('/api/v1/services', $serviceData);

        $response->assertStatus(401);
    }

    /** @test */
    public function service_creation_requires_valid_data()
    {
        Sanctum::actingAs($this->vendor);

        $response = $this->postJson('/api/v1/services', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors([
                    'title',
                    'description',
                    'short_description',
                    'category_id',
                    'price_type',
                    'base_price',
                    'currency',
                    'location_type'
                ]);
    }

    /** @test */
    public function vendor_can_view_their_services()
    {
        Sanctum::actingAs($this->vendor);

        // Create services for this vendor
        Service::factory()->count(3)->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ]);

        // Create service for another vendor
        $otherVendor = User::factory()->create(['roles' => ['vendor']]);
        Service::factory()->create([
            'user_id' => $otherVendor->id,
            'category_id' => $this->category->id
        ]);

        $response = $this->getJson('/api/v1/services/my-services');

        $response->assertStatus(200)
                ->assertJsonCount(3, 'data');

        // Verify all returned services belong to the authenticated vendor
        $services = $response->json('data');
        foreach ($services as $service) {
            $this->assertEquals($this->vendor->id, $service['user']['id']);
        }
    }

    /** @test */
    public function vendor_can_update_their_service()
    {
        Sanctum::actingAs($this->vendor);

        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'title' => 'Original Title'
        ]);

        $updateData = [
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'base_price' => 200.00
        ];

        $response = $this->putJson("/api/v1/services/{$service->id}", $updateData);

        $response->assertStatus(200)
                ->assertJsonFragment([
                    'message' => 'Service updated successfully'
                ]);

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'title' => 'Updated Title',
            'base_price' => 200.00
        ]);
    }

    /** @test */
    public function vendor_cannot_update_other_vendors_service()
    {
        $otherVendor = User::factory()->create(['roles' => ['vendor']]);
        $service = Service::factory()->create([
            'user_id' => $otherVendor->id,
            'category_id' => $this->category->id
        ]);

        Sanctum::actingAs($this->vendor);

        $response = $this->putJson("/api/v1/services/{$service->id}", [
            'title' => 'Hacked Title'
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function vendor_can_delete_their_service()
    {
        Sanctum::actingAs($this->vendor);

        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ]);

        $response = $this->deleteJson("/api/v1/services/{$service->id}");

        $response->assertStatus(200)
                ->assertJsonFragment([
                    'message' => 'Service deleted successfully'
                ]);

        $this->assertSoftDeleted('services', [
            'id' => $service->id
        ]);
    }

    /** @test */
    public function users_can_view_service_by_slug()
    {
        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'slug' => 'test-service',
            'status' => 'active'
        ]);

        $response = $this->getJson("/api/v1/services/{$this->vendor->slug}/test-service");

        $response->assertStatus(200)
                ->assertJsonFragment([
                    'id' => $service->id,
                    'title' => $service->title,
                    'slug' => 'test-service'
                ]);
    }

    /** @test */
    public function users_can_search_services()
    {
        // Create services with different titles
        Service::factory()->create([
            'title' => 'Hair Styling Service',
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'active'
        ]);

        Service::factory()->create([
            'title' => 'Car Washing Service',
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/v1/services/search?q=hair');

        $response->assertStatus(200);
        // Note: Actual search functionality depends on Scout configuration
    }

    /** @test */
    public function users_can_filter_services_by_category()
    {
        $category2 = ServiceCategory::factory()->create([
            'slug' => 'category-2'
        ]);

        // Create services in different categories
        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'active'
        ]);

        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $category2->id,
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/v1/services?category=' . $this->category->slug);

        $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function users_can_filter_services_by_price_range()
    {
        // Create services with different prices
        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'base_price' => 50.00,
            'status' => 'active'
        ]);

        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'base_price' => 150.00,
            'status' => 'active'
        ]);

        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'base_price' => 250.00,
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/v1/services?price_min=100&price_max=200');

        $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function users_can_get_service_categories()
    {
        // Create additional categories
        ServiceCategory::factory()->count(3)->create(['is_active' => true]);

        $response = $this->getJson('/api/v1/service-categories');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'icon',
                        'color',
                        'is_active'
                    ]
                ]);
    }

    /** @test */
    public function vendor_can_view_service_analytics()
    {
        Sanctum::actingAs($this->vendor);

        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ]);

        $response = $this->getJson("/api/v1/services/{$service->id}/analytics");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'views' => [
                        'total',
                        'this_month',
                        'trend'
                    ],
                    'bookings' => [
                        'total',
                        'this_month',
                        'revenue'
                    ],
                    'ratings' => [
                        'average',
                        'count',
                        'distribution'
                    ],
                    'conversion' => [
                        'view_to_booking',
                        'favorites'
                    ]
                ]);
    }

    /** @test */
    public function vendor_cannot_view_analytics_for_other_vendors_service()
    {
        $otherVendor = User::factory()->create(['roles' => ['vendor']]);
        $service = Service::factory()->create([
            'user_id' => $otherVendor->id,
            'category_id' => $this->category->id
        ]);

        Sanctum::actingAs($this->vendor);

        $response = $this->getJson("/api/v1/services/{$service->id}/analytics");

        $response->assertStatus(403);
    }

    /** @test */
    public function services_are_paginated_correctly()
    {
        // Create 25 services
        Service::factory()->count(25)->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/v1/services?per_page=10');

        $response->assertStatus(200)
                ->assertJsonCount(10, 'data')
                ->assertJsonFragment([
                    'per_page' => 10,
                    'total' => 25
                ]);
    }

    /** @test */
    public function services_can_be_sorted_by_price()
    {
        // Create services with different prices
        $service1 = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'base_price' => 300.00,
            'status' => 'active'
        ]);

        $service2 = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'base_price' => 100.00,
            'status' => 'active'
        ]);

        $service3 = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'base_price' => 200.00,
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/v1/services?sort=price_low');

        $response->assertStatus(200);

        $services = $response->json('data');
        $this->assertEquals($service2->id, $services[0]['id']); // Lowest price first
        $this->assertEquals($service3->id, $services[1]['id']);
        $this->assertEquals($service1->id, $services[2]['id']); // Highest price last
    }
}