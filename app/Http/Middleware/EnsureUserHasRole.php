<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Check if user has the required role
        if (!$user->hasRole($role)) {
            return response()->json([
                'success' => false,
                'message' => "Access denied. {$role} role required.",
                'error_code' => 'INSUFFICIENT_PERMISSIONS',
                'data' => [
                    'required_role' => $role,
                    'user_roles' => $user->roles ?? [],
                ],
            ], 403);
        }

        return $next($request);
    }
}