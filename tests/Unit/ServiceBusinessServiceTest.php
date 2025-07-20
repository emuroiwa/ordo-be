<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Repositories\ServiceRepository;
use App\Services\ImageProcessingService;
use App\Services\ServiceBusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ServiceBusinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceBusinessService $service;
    private ServiceRepository $repository;
    private ImageProcessingService $imageProcessor;
    private User $vendor;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(ServiceRepository::class);
        $this->imageProcessor = Mockery::mock(ImageProcessingService::class);
        
        $this->service = new ServiceBusinessService($this->repository, $this->imageProcessor);
        
        $this->vendor = User::factory()->create([
            'roles' => ['vendor'],
            'slug' => 'test-vendor'
        ]);

        $this->category = ServiceCategory::factory()->create([
            'slug' => 'test-category',
            'is_active' => true
        ]);

        Cache::flush();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_get_services_with_caching()
    {
        $filters = ['category' => 'test'];
        $perPage = 12;
        $mockPaginator = Mockery::mock('Illuminate\Pagination\LengthAwarePaginator');

        $this->repository
            ->shouldReceive('getServicesWithFilters')
            ->once()
            ->with($filters, $perPage)
            ->andReturn($mockPaginator);

        // First call should hit the repository
        $result1 = $this->service->getServices($filters, $perPage);
        $this->assertEquals($mockPaginator, $result1);

        // Second call should hit cache (repository shouldn't be called again)
        $result2 = $this->service->getServices($filters, $perPage);
        $this->assertEquals($mockPaginator, $result2);
    }

    /** @test */
    public function it_can_get_user_services()
    {
        $userId = $this->vendor->id;
        $filters = ['status' => 'active'];
        $perPage = 20;
        $mockPaginator = Mockery::mock('Illuminate\Pagination\LengthAwarePaginator');

        $this->repository
            ->shouldReceive('getUserServices')
            ->once()
            ->with($userId, $filters, $perPage)
            ->andReturn($mockPaginator);

        $result = $this->service->getUserServices($userId, $filters, $perPage);
        $this->assertEquals($mockPaginator, $result);
    }

    /** @test */
    public function it_can_create_service_without_images()
    {
        $serviceData = [
            'title' => 'Test Service',
            'description' => 'Test description',
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ];

        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');

        $this->repository
            ->shouldReceive('slugExists')
            ->with('test-service', null)
            ->andReturn(false);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) {
                return isset($data['slug']) && $data['title'] === 'Test Service';
            }))
            ->andReturn($mockService);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $result = $this->service->createService($serviceData);
        $this->assertEquals($mockService, $result);
    }

    /** @test */
    public function it_can_create_service_with_images()
    {
        $serviceData = [
            'title' => 'Test Service',
            'description' => 'Test description',
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ];

        $image1 = UploadedFile::fake()->image('test1.jpg');
        $image2 = UploadedFile::fake()->image('test2.jpg');
        $images = [$image1, $image2];

        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');

        $this->repository
            ->shouldReceive('slugExists')
            ->with('test-service', null)
            ->andReturn(false);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->andReturn($mockService);

        $this->imageProcessor
            ->shouldReceive('processServiceImage')
            ->twice()
            ->with(Mockery::type(UploadedFile::class), 'service-id');

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $result = $this->service->createService($serviceData, $images);
        $this->assertEquals($mockService, $result);
    }

    /** @test */
    public function it_rolls_back_transaction_on_service_creation_failure()
    {
        $serviceData = [
            'title' => 'Test Service',
            'user_id' => $this->vendor->id,
            'category_id' => $this->category->id
        ];

        $this->repository
            ->shouldReceive('slugExists')
            ->with('test-service', null)
            ->andReturn(false);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->andThrow(new \Exception('Database error'));

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollBack')->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->service->createService($serviceData);
    }

    /** @test */
    public function it_can_update_service_without_title_change()
    {
        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('title')->andReturn('Original Title');
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');
        $mockService->shouldReceive('refresh')->once();

        $updateData = ['description' => 'Updated description'];

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with($mockService, $updateData);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $result = $this->service->updateService($mockService, $updateData);
        $this->assertEquals($mockService, $result);
    }

    /** @test */
    public function it_can_update_service_with_title_change()
    {
        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('title')->andReturn('Original Title');
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');
        $mockService->shouldReceive('refresh')->once();

        $updateData = ['title' => 'New Title'];

        $this->repository
            ->shouldReceive('slugExists')
            ->once()
            ->with('new-title', 'service-id')
            ->andReturn(false);

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with($mockService, Mockery::on(function ($data) {
                return $data['title'] === 'New Title' && $data['slug'] === 'new-title';
            }));

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $result = $this->service->updateService($mockService, $updateData);
        $this->assertEquals($mockService, $result);
    }

    /** @test */
    public function it_can_delete_service()
    {
        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');

        $this->repository
            ->shouldReceive('delete')
            ->once()
            ->with($mockService);

        $this->imageProcessor
            ->shouldReceive('deleteServiceImages')
            ->once()
            ->with('service-id');

        $this->service->deleteService($mockService);
    }

    /** @test */
    public function it_can_find_service_by_id()
    {
        $serviceId = 'service-id';
        $mockService = Mockery::mock(Service::class);

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with($serviceId)
            ->andReturn($mockService);

        $result = $this->service->findServiceById($serviceId);
        $this->assertEquals($mockService, $result);
    }

    /** @test */
    public function it_can_find_service_by_slug()
    {
        $userSlug = 'user-slug';
        $serviceSlug = 'service-slug';
        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('incrementViewCount')->once();

        $this->repository
            ->shouldReceive('findBySlug')
            ->once()
            ->with($userSlug, $serviceSlug)
            ->andReturn($mockService);

        $result = $this->service->findServiceBySlug($userSlug, $serviceSlug);
        $this->assertEquals($mockService, $result);
    }

    /** @test */
    public function it_can_search_services()
    {
        $query = 'test query';
        $perPage = 12;
        $mockPaginator = Mockery::mock('Illuminate\Pagination\LengthAwarePaginator');

        $this->repository
            ->shouldReceive('search')
            ->once()
            ->with($query, $perPage)
            ->andReturn($mockPaginator);

        $result = $this->service->searchServices($query, $perPage);
        $this->assertEquals($mockPaginator, $result);
    }

    /** @test */
    public function it_returns_all_services_for_empty_search_query()
    {
        $perPage = 12;
        $mockPaginator = Mockery::mock('Illuminate\Pagination\LengthAwarePaginator');

        $this->repository
            ->shouldReceive('getServicesWithFilters')
            ->once()
            ->with([], $perPage)
            ->andReturn($mockPaginator);

        $result = $this->service->searchServices('', $perPage);
        $this->assertEquals($mockPaginator, $result);
    }

    /** @test */
    public function it_can_get_categories_with_caching()
    {
        $mockCollection = collect(['category1', 'category2']);

        $this->repository
            ->shouldReceive('getCategories')
            ->once()
            ->andReturn($mockCollection);

        // First call should hit the repository
        $result1 = $this->service->getCategories();
        $this->assertEquals($mockCollection, $result1);

        // Second call should hit cache
        $result2 = $this->service->getCategories();
        $this->assertEquals($mockCollection, $result2);
    }

    /** @test */
    public function it_generates_unique_slug()
    {
        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');

        $this->repository
            ->shouldReceive('slugExists')
            ->with('test-title', null)
            ->andReturn(false);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['slug'] === 'test-title';
            }))
            ->andReturn($mockService);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $this->service->createService(['title' => 'Test Title']);
    }

    /** @test */
    public function it_generates_unique_slug_with_counter_when_exists()
    {
        $mockService = Mockery::mock(Service::class);
        $mockService->shouldReceive('getAttribute')->with('id')->andReturn('service-id');

        $this->repository
            ->shouldReceive('slugExists')
            ->with('test-title', null)
            ->andReturn(true);

        $this->repository
            ->shouldReceive('slugExists')
            ->with('test-title-1', null)
            ->andReturn(false);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['slug'] === 'test-title-1';
            }))
            ->andReturn($mockService);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();

        $this->service->createService(['title' => 'Test Title']);
    }
}