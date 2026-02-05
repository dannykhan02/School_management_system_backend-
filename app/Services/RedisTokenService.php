<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RedisTokenService
{
    protected $prefix = 'auth_token:';
    protected $userTokensPrefix = 'user_tokens:';
    protected $ttl = 3600; // 1 hour (configurable)

    public function __construct()
    {
        // Get TTL from config or env
        $this->ttl = config('auth.token_ttl', 3600);
    }

    /**
     * Generate and store a token for a user
     */
    public function generateToken(int $userId, array $metadata = []): string
    {
        $token = Str::random(64);
        $key = $this->prefix . $token;
        $userTokensKey = $this->userTokensPrefix . $userId;
        
        $tokenData = array_merge([
            'user_id' => $userId,
            'created_at' => Carbon::now()->toIso8601String(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $metadata);
        
        // Store token data with expiration
        Redis::setex($key, $this->ttl, json_encode($tokenData));
        
        // Add token to user's token list (for multiple device support)
        Redis::sadd($userTokensKey, $token);
        Redis::expire($userTokensKey, $this->ttl);
        
        return $token;
    }

    /**
     * Validate and retrieve token data
     */
    public function validateToken(string $token): ?array
    {
        $key = $this->prefix . $token;
        $data = Redis::get($key);
        
        if (!$data) {
            return null;
        }
        
        return json_decode($data, true);
    }

    /**
     * Get user ID from token
     */
    public function getUserIdFromToken(string $token): ?int
    {
        $tokenData = $this->validateToken($token);
        return $tokenData['user_id'] ?? null;
    }

    /**
     * Revoke a specific token
     */
    public function revokeToken(string $token): bool
    {
        $tokenData = $this->validateToken($token);
        
        if ($tokenData) {
            $userId = $tokenData['user_id'];
            $userTokensKey = $this->userTokensPrefix . $userId;
            
            // Remove from user's token list
            Redis::srem($userTokensKey, $token);
        }
        
        // Delete the token
        $key = $this->prefix . $token;
        return (bool) Redis::del($key);
    }

    /**
     * Revoke all tokens for a user (logout from all devices)
     */
    public function revokeAllUserTokens(int $userId): bool
    {
        $userTokensKey = $this->userTokensPrefix . $userId;
        $tokens = Redis::smembers($userTokensKey);
        
        if (empty($tokens)) {
            return true;
        }
        
        // Delete all tokens
        foreach ($tokens as $token) {
            $key = $this->prefix . $token;
            Redis::del($key);
        }
        
        // Clear the user's token list
        Redis::del($userTokensKey);
        
        return true;
    }

    /**
     * Refresh token TTL (sliding expiration)
     */
    public function refreshToken(string $token): bool
    {
        $key = $this->prefix . $token;
        $tokenData = $this->validateToken($token);
        
        if (!$tokenData) {
            return false;
        }
        
        // Refresh token expiration
        Redis::expire($key, $this->ttl);
        
        // Refresh user tokens list expiration
        $userTokensKey = $this->userTokensPrefix . $tokenData['user_id'];
        Redis::expire($userTokensKey, $this->ttl);
        
        return true;
    }

    /**
     * Get all active tokens for a user
     */
    public function getUserTokens(int $userId): array
    {
        $userTokensKey = $this->userTokensPrefix . $userId;
        return Redis::smembers($userTokensKey) ?: [];
    }

    /**
     * Count active sessions for a user
     */
    public function countUserSessions(int $userId): int
    {
        $userTokensKey = $this->userTokensPrefix . $userId;
        return Redis::scard($userTokensKey);
    }
}