<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    // Get all users for the current school with optional role filtering
    public function index(Request $request)
    {
        $authUser = Auth::user();
        
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

    // Create a user for the current school only
    public function store(Request $request)
    {
        $authUser = Auth::user();

        $data = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'full_name' => 'required|string',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string',
            'gender' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        // Force the user to belong to the same school as the logged-in user
        $data['school_id'] = $authUser->school_id;

        // Default password: first name + 123
        $firstName = explode(' ', trim($data['full_name']))[0];
        $defaultPassword = $firstName . '123';
        $data['password'] = Hash::make($defaultPassword);
        $data['status'] = $data['status'] ?? 'active';

        $user = User::create($data);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }

    // Show a single user 
    public function show(User $user)
    {
        $authUser = Auth::user();

        if ($user->school_id !== $authUser->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $user->load('role', 'school');
    }

    // Update user (same school restriction)
    public function update(Request $request, User $user)
    {
        $authUser = Auth::user();

        if ($user->school_id !== $authUser->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'role_id' => 'sometimes|required|exists:roles,id',
            'full_name' => 'sometimes|required|string',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string',
            'gender' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $user->update($data);

        return response()->json($user);
    }

    // Delete user (same school restriction)
    public function destroy(User $user)
    {
        $authUser = Auth::user();

        if ($user->school_id !== $authUser->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }
}