<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\RedisTokenService;
use App\Models\User;

class AuthenticateWithRedis
{
    protected $tokenService;

    public function __construct(RedisTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'message' => 'Token not provided'
            ], 401);
        }
        
        $tokenData = $this->tokenService->validateToken($token);
        
        if (!$tokenData) {
            return response()->json([
                'message' => 'Invalid or expired token'
            ], 401);
        }
        
        // Get user from database
        $user = User::find($tokenData['user_id']);
        
        if (!$user || $user->status !== 'active') {
            return response()->json([
                'message' => 'User not found or inactive'
            ], 401);
        }
        
        // Refresh token TTL on each request (sliding expiration)
        $this->tokenService->refreshToken($token);
        
        // Attach user and token data to request
        $request->merge([
            'auth_user' => $user,
            'token_data' => $tokenData
        ]);
        
        // Set authenticated user for Auth facade
        auth()->setUser($user);
        
        return $next($request);
    }
}