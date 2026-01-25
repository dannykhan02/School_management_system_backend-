<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class UserController extends BaseController
{
    // Get all users for the current school with optional role filtering
    public function index(Request $request)
    {
        $authUser = $this->getUser($request);
        
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }
        
        $query = User::where('school_id', $authUser->school_id);
        
        // Filter by role if provided
        if ($request->has('role_id')) {
            $query->where('role_id', $request->role_id);
        }
        
        // Filter out users who are already teachers if requested
        if ($request->has('exclude_teachers') && $request->exclude_teachers) {
            $teacherUserIds = \App\Models\Teacher::pluck('user_id');
            $query->whereNotIn('id', $teacherUserIds);
        }
        
        $users = $query->with('role')->get();
        
        return response()->json($users);
    }

    // Get super admins for the system
    public function getSuperAdmins(Request $request)
    {
        $authUser = $this->getUser($request);
        
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Find the super admin role - try different possible names including the exact one from your database
        $superAdminRole = Role::where(function($query) {
            $query->where('name', 'super-admin')  // Exact match from your database
                  ->orWhere('name', 'superadmin')
                  ->orWhere('name', 'super_admin')
                  ->orWhere('name', 'Super Admin')
                  ->orWhere('name', 'Super Administrator');
        })->first();
        
        if (!$superAdminRole) {
            return response()->json([
                'message' => 'Super admin role not found in the system',
                'super_admins' => []
            ], 200);
        }

        // Get all super admins - they don't belong to any specific school (school_id is null or 0)
        $superAdmins = User::where('role_id', $superAdminRole->id)
            ->where('status', 'active')
            ->select('id', 'full_name', 'email', 'phone', 'created_at', 'status')
            ->orderBy('full_name')
            ->get()
            ->map(function($admin) {
                return [
                    'id' => $admin->id,
                    'name' => $admin->full_name,
                    'email' => $admin->email,
                    'phone' => $admin->phone,
                    'created_at' => $admin->created_at ? $admin->created_at->format('Y-m-d H:i:s') : null,
                    'is_active' => $admin->status === 'active'
                ];
            });

        return response()->json([
            'count' => $superAdmins->count(),
            'super_admins' => $superAdmins,
            'role_name' => $superAdminRole->name
        ]);
    }

    // Create a user for the current school only
    public function store(Request $request)
    {
        $authUser = $this->getUser($request);
        
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $data = $request->validate([
                'role_id' => 'required|exists:roles,id',
                'full_name' => 'required|string',
                'email' => 'nullable|email|unique:users,email',
                'phone' => 'nullable|string',
                'gender' => 'nullable|string',
                'status' => 'nullable|in:active,inactive',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        // Force the user to belong to the same school as the logged-in user
        $data['school_id'] = $authUser->school_id;
        $data['status'] = $data['status'] ?? 'active';
        
        // Set default password before creating the user
        $data['password'] = Hash::make('password123');
        $data['must_change_password'] = true;

        $user = User::create($data);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }

    // Show a single user 
    public function show(Request $request, User $user)
    {
        $authUser = $this->getUser($request);
        
        $authError = $this->checkAuthorization($authUser, $user);
        if ($authError) {
            return $authError;
        }

        return $user->load('role', 'school');
    }

    // Update user (same school restriction)
    public function update(Request $request, User $user)
    {
        $authUser = $this->getUser($request);
        
        $authError = $this->checkAuthorization($authUser, $user);
        if ($authError) {
            return $authError;
        }

        try {
            $data = $request->validate([
                'role_id' => 'sometimes|required|exists:roles,id',
                'full_name' => 'sometimes|required|string',
                'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
                'phone' => 'nullable|string',
                'gender' => 'nullable|string',
                'status' => 'nullable|in:active,inactive',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        $user->update($data);

        return response()->json($user);
    }

    // Update own profile (authenticated user updates their own details)
    public function updateProfile(Request $request)
    {
        $authUser = $this->getUser($request);
        
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $data = $request->validate([
                'full_name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|unique:users,email,' . $authUser->id,
                'phone' => 'nullable|string|max:20',
                'gender' => 'nullable|string|in:male,female,other',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        // Users cannot change their own role_id or status through this endpoint
        // Only allow updating personal information
        $authUser->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $authUser->load('role', 'school')
        ]);
    }

    // Delete user (same school restriction)
    public function destroy(Request $request, User $user)
    {
        $authUser = $this->getUser($request);
        
        $authError = $this->checkAuthorization($authUser, $user);
        if ($authError) {
            return $authError;
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    // Helper function to check authorization - Changed from private to protected
    protected function checkAuthorization($authUser, $user)
    {
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if ($authUser->id !== $user->id && $authUser->school_id !== $user->school_id) {
            return response()->json(['message' => 'Forbidden. You can only manage users in your own school.'], 403);
        }

        return null;
    }
}