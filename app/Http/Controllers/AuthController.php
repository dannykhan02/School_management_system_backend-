<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Services\RedisTokenService;

class AuthController extends BaseController
{
    protected $tokenService;

    public function __construct(RedisTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required',
        ]);

        $loginInput = $request->input('login');
        $password = $request->input('password');

        // Check if input is an email or a full name
        $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'full_name';

        // Find user by that field
        $user = User::where($fieldType, $loginInput)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Check if user is inactive
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Your account is inactive. Please contact your administrator.'
            ], 403);
        }

        // Generate Redis token with metadata
        $token = $this->tokenService->generateToken($user->id, [
            'role_id' => $user->role_id,
            'role' => optional($user->role)->name,
            'school_id' => $user->school_id,
            'login_method' => $fieldType,
        ]);

        // Count active sessions
        $activeSessions = $this->tokenService->countUserSessions($user->id);

        // Return user info + token
        return response()->json([
            'id' => $user->id,
            'school_id' => $user->school_id,
            'role_id' => $user->role_id,
            'role' => optional($user->role)->name,
            'role_name' => optional($user->role)->name,
            'name' => $user->full_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'gender' => $user->gender,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at,
            'must_change_password' => $user->must_change_password ?? false,
            'token' => $token,
            'active_sessions' => $activeSessions,
            'token_expires_in' => config('auth.token_ttl', 3600), // seconds
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->auth_user ?? auth()->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Please log in.'
            ], 401);
        }

        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|min:8|confirmed|different:current_password',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.'
            ], 403);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->must_change_password = false;
        $user->last_password_changed_at = now();
        $user->save();

        // Optionally: Revoke all tokens to force re-login
        // $this->tokenService->revokeAllUserTokens($user->id);

        return response()->json([
            'message' => 'Password changed successfully.'
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'message' => 'No active session found.'
            ], 200);
        }

        // Revoke the current token
        $this->tokenService->revokeToken($token);
        
        return response()->json([
            'message' => 'Logged out successfully.'
        ]);
    }

    public function logoutAll(Request $request)
    {
        $user = $request->auth_user ?? auth()->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Revoke all tokens for this user
        $this->tokenService->revokeAllUserTokens($user->id);
        
        return response()->json([
            'message' => 'Logged out from all devices successfully.'
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->auth_user ?? auth()->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Get token data
        $tokenData = $request->token_data ?? [];
        $activeSessions = $this->tokenService->countUserSessions($user->id);

        return response()->json([
            'id' => $user->id,
            'school_id' => $user->school_id,
            'role_id' => $user->role_id,
            'role' => optional($user->role)->name,
            'role_name' => optional($user->role)->name,
            'name' => $user->full_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'gender' => $user->gender,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at,
            'must_change_password' => $user->must_change_password ?? false,
            'active_sessions' => $activeSessions,
            'current_session' => [
                'ip_address' => $tokenData['ip_address'] ?? null,
                'user_agent' => $tokenData['user_agent'] ?? null,
                'created_at' => $tokenData['created_at'] ?? null,
            ]
        ]);
    }

    public function activeSessions(Request $request)
    {
        $user = $request->auth_user ?? auth()->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $tokens = $this->tokenService->getUserTokens($user->id);
        $sessions = [];

        foreach ($tokens as $token) {
            $tokenData = $this->tokenService->validateToken($token);
            if ($tokenData) {
                $sessions[] = [
                    'token_preview' => substr($token, 0, 10) . '...',
                    'ip_address' => $tokenData['ip_address'] ?? null,
                    'user_agent' => $tokenData['user_agent'] ?? null,
                    'created_at' => $tokenData['created_at'] ?? null,
                    'is_current' => $token === $request->bearerToken(),
                ];
            }
        }

        return response()->json([
            'total_sessions' => count($sessions),
            'sessions' => $sessions
        ]);
    }
}