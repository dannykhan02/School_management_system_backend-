<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string', // can be email OR full name
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

        // Create token
        $token = $user->createToken('api-token')->plainTextToken;

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
            'token' => $token,
        ]);
    }

    public function changePassword(Request $request)
    {
        // Get user via Auth (when protected by middleware)
        $user = Auth::user();
        
        // Fallback: Try Sanctum guard
        if (!$user) {
            $user = Auth::guard('sanctum')->user();
        }

        // If still no user, return error
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Please log in.'
            ], 401);
        }

        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|min:6|confirmed',
        ]);

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.'
            ], 403);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->must_change_password = false;
        $user->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function logout(Request $request)
    {
        // Get the authenticated user
        $user = $request->user();
        
        // If no user from request, try Auth guard
        if (!$user) {
            $user = Auth::guard('sanctum')->user();
        }

        // If still no user, just return success (already logged out)
        if (!$user) {
            return response()->json([
                'message' => 'Already logged out or not authenticated.'
            ], 200);
        }

        // Delete all tokens for this user
        $user->tokens()->delete();
        
        return response()->json([
            'message' => 'Logged out successfully.'
        ]);
    }

    public function user(Request $request)
    {
        // Get the authenticated user
        $user = $request->user();
        
        // Fallback to Sanctum guard
        if (!$user) {
            $user = Auth::guard('sanctum')->user();
        }

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Return user with role information
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
        ]);
    }
}