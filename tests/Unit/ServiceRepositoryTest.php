<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Repositories\ServiceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ServiceRepository $repository;
    private User $vendor;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ServiceRepository();
        
        $this->vendor = User::factory()->create([
            'roles' => ['vendor'],
            'slug' => 'test-vendor'
        ]);

        $this->category = ServiceCategory::factory()->create([
            'slug' => 'test-category',
            'is_active' => true
        ]);
    }

    /** @test */
    public function it_can_create_a_service()
    {
        $data = [
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'title' => 'Test Service',
            'description' => 'Test description',
            'short_description' => 'Short desc',
            'slug' => 'test-service',
            'price_type' => 'fixed',
            'base_price' => 100.00,
            'currency' => 'ZAR',
            'location_type' => 'client_location'
        ];

        $service = $this->repository->create($data);

        $this->assertInstanceOf(Service::class, $service);
        $this->assertEquals('Test Service', $service->title);
        $this->assertEquals($this->vendor->id, $service->user_id);
    }

    /** @test */
    public function it_can_update_a_service()
    {
        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'title' => 'Original Title'
        ]);

        $updated = $this->repository->update($service, [
            'title' => 'Updated Title',
            'base_price' => 200.00
        ]);

        $this->assertTrue($updated);
        $service->refresh();
        $this->assertEquals('Updated Title', $service->title);
        $this->assertEquals(200.00, $service->base_price);
    }

    /** @test */
    public function it_can_find_service_by_id()
    {
        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ]);

        $found = $this->repository->findById($service->id);

        $this->assertNotNull($found);
        $this->assertEquals($service->id, $found->id);
        $this->assertTrue($found->relationLoaded('category'));
        $this->assertTrue($found->relationLoaded('user'));
    }

    /** @test */
    public function it_can_find_service_by_slug()
    {
        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'slug' => 'test-service'
        ]);

        $found = $this->repository->findBySlug('test-vendor', 'test-service');

        $this->assertNotNull($found);
        $this->assertEquals($service->id, $found->id);
        $this->assertTrue($found->relationLoaded('category'));
        $this->assertTrue($found->relationLoaded('user'));
    }

    /** @test */
    public function it_returns_null_for_non_existent_service()
    {
        $found = $this->repository->findById('non-existent-id');
        $this->assertNull($found);

        $found = $this->repository->findBySlug('non-existent', 'service');
        $this->assertNull($found);
    }

    /** @test */
    public function it_can_check_if_slug_exists()
    {
        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'slug' => 'existing-slug'
        ]);

        $this->assertTrue($this->repository->slugExists('existing-slug'));
        $this->assertFalse($this->repository->slugExists('non-existing-slug'));
    }

    /** @test */
    public function it_excludes_service_id_when_checking_slug_existence()
    {
        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'slug' => 'existing-slug'
        ]);

        // Should return false when excluding the same service
        $this->assertFalse($this->repository->slugExists('existing-slug', $service->id));
        
        // Should return true for different service
        $this->assertTrue($this->repository->slugExists('existing-slug', 'different-id'));
    }

    /** @test */
    public function it_can_get_user_services()
    {
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

        $services = $this->repository->getUserServices($this->vendor->id);

        $this->assertCount(3, $services);
        foreach ($services as $service) {
            $this->assertEquals($this->vendor->id, $service->user_id);
        }
    }

    /** @test */
    public function it_can_filter_user_services_by_status()
    {
        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'active'
        ]);

        Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id,
            'status' => 'draft'
        ]);

        $activeServices = $this->repository->getUserServices($this->vendor->id, ['status' => 'active']);
        $this->assertCount(1, $activeServices);

        $draftServices = $this->repository->getUserServices($this->vendor->id, ['status' => 'draft']);
        $this->assertCount(1, $draftServices);
    }

    /** @test */
    public function it_can_get_services_with_category_filter()
    {
        $category2 = ServiceCategory::factory()->create([
            'slug' => 'category-2',
            'is_active' => true
        ]);

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

        $services = $this->repository->getServicesWithFilters(['category' => 'test-category']);
        $this->assertCount(1, $services);
    }

    /** @test */
    public function it_can_get_services_with_price_filter()
    {
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

        $services = $this->repository->getServicesWithFilters([
            'price_min' => 100,
            'price_max' => 200
        ]);

        $this->assertCount(1, $services);
    }

    /** @test */
    public function it_can_get_active_categories()
    {
        ServiceCategory::factory()->create(['is_active' => true]);
        ServiceCategory::factory()->create(['is_active' => false]);

        $categories = $this->repository->getCategories();

        $this->assertCount(2, $categories); // 1 from setUp + 1 active created here
        foreach ($categories as $category) {
            $this->assertTrue($category->is_active);
        }
    }

    /** @test */
    public function it_can_soft_delete_a_service()
    {
        $service = Service::factory()->create([
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ]);

        $deleted = $this->repository->delete($service);

        $this->assertTrue($deleted);
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }
}