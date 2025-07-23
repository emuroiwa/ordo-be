<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Exception;

class HealthController extends Controller
{
    /**
     * Health check endpoint for Docker and load balancers
     */
    public function check(): JsonResponse
    {
        $checks = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env'),
            'checks' => []
        ];

        // Database check
        try {
            DB::connection()->getPdo();
            $checks['checks']['database'] = [
                'status' => 'healthy',
                'connection' => DB::connection()->getDatabaseName()
            ];
        } catch (Exception $e) {
            $checks['status'] = 'unhealthy';
            $checks['checks']['database'] = [
                'status' => 'unhealthy',
                'error' => 'Database connection failed'
            ];
        }

        // Cache check (if Redis is configured)
        if (config('cache.default') === 'redis') {
            try {
                Cache::put('health_check', 'ok', 60);
                $cacheValue = Cache::get('health_check');
                
                $checks['checks']['cache'] = [
                    'status' => $cacheValue === 'ok' ? 'healthy' : 'unhealthy',
                    'driver' => config('cache.default')
                ];
            } catch (Exception $e) {
                $checks['status'] = 'unhealthy';
                $checks['checks']['cache'] = [
                    'status' => 'unhealthy',
                    'error' => 'Cache connection failed'
                ];
            }
        } else {
            $checks['checks']['cache'] = [
                'status' => 'skipped',
                'driver' => config('cache.default')
            ];
        }

        // Storage check
        try {
            $storageChecks = [
                'disk' => config('filesystems.default'),
                'writable' => is_writable(storage_path('logs'))
            ];
            
            $checks['checks']['storage'] = [
                'status' => $storageChecks['writable'] ? 'healthy' : 'unhealthy',
                'details' => $storageChecks
            ];
            
            if (!$storageChecks['writable']) {
                $checks['status'] = 'unhealthy';
            }
        } catch (Exception $e) {
            $checks['status'] = 'unhealthy';
            $checks['checks']['storage'] = [
                'status' => 'unhealthy',
                'error' => 'Storage check failed'
            ];
        }

        // Queue check (if using Redis/database queue)
        if (in_array(config('queue.default'), ['redis', 'database'])) {
            try {
                // Simple queue health check
                $checks['checks']['queue'] = [
                    'status' => 'healthy',
                    'driver' => config('queue.default')
                ];
            } catch (Exception $e) {
                $checks['status'] = 'unhealthy';
                $checks['checks']['queue'] = [
                    'status' => 'unhealthy',
                    'error' => 'Queue check failed'
                ];
            }
        }

        // Return appropriate HTTP status code
        $httpStatus = $checks['status'] === 'healthy' ? 200 : 503;
        
        return response()->json($checks, $httpStatus);
    }

    /**
     * Simple health check for load balancers
     */
    public function simple()
    {
        return response('healthy', 200, [
            'Content-Type' => 'text/plain'
        ]);
    }

    /**
     * Readiness check - more thorough than health check
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'status' => 'ready',
            'timestamp' => now()->toISOString(),
            'checks' => []
        ];

        // Database migration check
        try {
            // Check if migrations table exists and has been run
            $migrationCount = DB::table('migrations')->count();
            
            $checks['checks']['migrations'] = [
                'status' => $migrationCount > 0 ? 'ready' : 'not_ready',
                'count' => $migrationCount
            ];
            
            if ($migrationCount === 0) {
                $checks['status'] = 'not_ready';
            }
        } catch (Exception $e) {
            $checks['status'] = 'not_ready';
            $checks['checks']['migrations'] = [
                'status' => 'not_ready',
                'error' => 'Migration check failed'
            ];
        }

        // Essential data check
        try {
            // Check if essential data exists (e.g., service categories)
            $categoryCount = DB::table('service_categories')->count();
            
            $checks['checks']['essential_data'] = [
                'status' => $categoryCount > 0 ? 'ready' : 'not_ready',
                'categories' => $categoryCount
            ];
            
            if ($categoryCount === 0) {
                $checks['status'] = 'not_ready';
            }
        } catch (Exception $e) {
            $checks['status'] = 'not_ready';
            $checks['checks']['essential_data'] = [
                'status' => 'not_ready',
                'error' => 'Essential data check failed'
            ];
        }

        // Configuration check
        $requiredConfig = [
            'app.key',
            'app.url',
            'services.yoco.secret_key'
        ];

        $configStatus = 'ready';
        $configDetails = [];

        foreach ($requiredConfig as $configKey) {
            $value = config($configKey);
            $isConfigured = !empty($value) && $value !== 'your-key-here';
            
            $configDetails[$configKey] = [
                'configured' => $isConfigured,
                'present' => !empty($value)
            ];
            
            if (!$isConfigured) {
                $configStatus = 'not_ready';
                $checks['status'] = 'not_ready';
            }
        }

        $checks['checks']['configuration'] = [
            'status' => $configStatus,
            'details' => $configDetails
        ];

        $httpStatus = $checks['status'] === 'ready' ? 200 : 503;
        
        return response()->json($checks, $httpStatus);
    }
}