<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVendorIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Allow access if user is not a vendor
        if (!$user || !$user->isVendor()) {
            return $next($request);
        }

        // Check if vendor is verified
        if (!$user->isVendorVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor verification required to access this resource.',
                'error_code' => 'VENDOR_NOT_VERIFIED',
                'data' => [
                    'verification_status' => $user->vendor_verification_status,
                    'verification_url' => route('vendor.verification.status'),
                ],
            ], 403);
        }

        return $next($request);
    }
}