<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // Rate limiting
        $key = 'register:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => 'Too many registration attempts. Please try again in ' . $seconds . ' seconds.'
            ], 429);
        }
        
        RateLimiter::hit($key, 300); // 5 minutes

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z\s]+$/'],
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()->symbols()->uncompromised()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'in:customer,vendor'],
            'business_name' => ['nullable', 'required_if:roles.*,vendor', 'string', 'max:255'],
            'service_category' => ['nullable', 'required_if:roles.*,vendor', 'string'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'roles' => $validated['roles'],
            'business_name' => $validated['business_name'] ?? null,
            'service_category' => $validated['service_category'] ?? null,
        ]);

        $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
        
        // Send welcome notification
        $notificationService = new NotificationService();
        $notificationService->sendWelcomeNotification($user);
        
        // Clear rate limiting on successful registration
        RateLimiter::clear($key);

        return response()->json([
            'message' => 'Registration successful',
            'user' => $this->formatUserResponse($user),
            'token' => $token
        ], 201);
    }

    public function login(Request $request)
    {
        // Rate limiting
        $key = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => 'Too many login attempts. Please try again in ' . $seconds . ' seconds.'
            ], 429);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60); // 1 minute penalty
            
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated. Please contact support.'],
            ]);
        }

        // Update last login
        $user->updateLastLogin();
        
        // Revoke existing tokens for security
        $user->tokens()->delete();
        
        $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
        
        // Clear rate limiting on successful login
        RateLimiter::clear($key);

        return response()->json([
            'message' => 'Login successful',
            'user' => $this->formatUserResponse($user),
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        
        // Delete current token
        $user->currentAccessToken()->delete();
        
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();
        
        // Delete all tokens for this user
        $user->tokens()->delete();
        
        return response()->json(['message' => 'Logged out from all devices successfully']);
    }

    public function user(Request $request)
    {
        return response()->json([
            'user' => $this->formatUserResponse($request->user())
        ]);
    }

    public function refreshToken(Request $request)
    {
        $user = $request->user();
        
        // Delete current token
        $user->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
        
        return response()->json([
            'message' => 'Token refreshed successfully',
            'token' => $token
        ]);
    }

    private function formatUserResponse(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles,
            'business_name' => $user->business_name,
            'service_category' => $user->service_category,
            'avatar_url' => $user->avatar_url,
            'email_verified_at' => $user->email_verified_at,
            'phone_verified_at' => $user->phone_verified_at,
            'last_login_at' => $user->last_login_at,
            'is_vendor' => $user->isVendor(),
            'is_customer' => $user->isCustomer(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}