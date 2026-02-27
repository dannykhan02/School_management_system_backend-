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
    // Get all users for the current school with pagination, search, and role filtering
    public function index(Request $request)
    {
        $authUser = $this->getUser($request);

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Pagination params
        $perPage = min((int) $request->get('per_page', 20), 100); // cap at 100
        $page    = max((int) $request->get('page', 1), 1);

        $query = User::where('school_id', $authUser->school_id)->with('role');

        // Filter by role if provided
        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        // Search by name or email
        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', $search)
                  ->orWhere('email',     'like', $search);
            });
        }

        // Filter out users who are already teachers if requested
        if ($request->boolean('exclude_teachers')) {
            $teacherUserIds = \App\Models\Teacher::pluck('user_id');
            $query->whereNotIn('id', $teacherUserIds);
        }

        // Order for consistent pagination
        $query->orderBy('full_name');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'          => $paginated->items(),
            'total'         => $paginated->total(),
            'per_page'      => $paginated->perPage(),
            'current_page'  => $paginated->currentPage(),
            'last_page'     => $paginated->lastPage(),
            'from'          => $paginated->firstItem(),
            'to'            => $paginated->lastItem(),
            'has_more_pages'=> $paginated->hasMorePages(),
        ]);
    }

    // Get super admins for the system
    public function getSuperAdmins(Request $request)
    {
        $authUser = $this->getUser($request);

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $superAdminRole = Role::where(function ($query) {
            $query->where('name', 'super-admin')
                  ->orWhere('name', 'superadmin')
                  ->orWhere('name', 'super_admin')
                  ->orWhere('name', 'Super Admin')
                  ->orWhere('name', 'Super Administrator');
        })->first();

        if (!$superAdminRole) {
            return response()->json([
                'message'      => 'Super admin role not found in the system',
                'super_admins' => [],
            ], 200);
        }

        $superAdmins = User::where('role_id', $superAdminRole->id)
            ->where('status', 'active')
            ->select('id', 'full_name', 'email', 'phone', 'created_at', 'status')
            ->orderBy('full_name')
            ->get()
            ->map(function ($admin) {
                return [
                    'id'         => $admin->id,
                    'name'       => $admin->full_name,
                    'email'      => $admin->email,
                    'phone'      => $admin->phone,
                    'created_at' => $admin->created_at ? $admin->created_at->format('Y-m-d H:i:s') : null,
                    'is_active'  => $admin->status === 'active',
                ];
            });

        return response()->json([
            'count'       => $superAdmins->count(),
            'super_admins'=> $superAdmins,
            'role_name'   => $superAdminRole->name,
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
                'role_id'   => 'required|exists:roles,id',
                'full_name' => 'required|string',
                'email'     => 'nullable|email|unique:users,email',
                'phone'     => 'nullable|string',
                'gender'    => 'nullable|string',
                'status'    => 'nullable|in:active,inactive',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }

        $data['school_id']            = $authUser->school_id;
        $data['status']               = $data['status'] ?? 'active';
        $data['password']             = Hash::make('password123');
        $data['must_change_password'] = true;

        $user = User::create($data);

        return response()->json([
            'message' => 'User created successfully',
            'user'    => $user,
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
                'role_id'   => 'sometimes|required|exists:roles,id',
                'full_name' => 'sometimes|required|string',
                'email'     => 'sometimes|required|email|unique:users,email,' . $user->id,
                'phone'     => 'nullable|string',
                'gender'    => 'nullable|string',
                'status'    => 'nullable|in:active,inactive',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }

        $user->update($data);

        return response()->json($user);
    }

    // Update own profile
    public function updateProfile(Request $request)
    {
        $authUser = $this->getUser($request);

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $data = $request->validate([
                'full_name' => 'sometimes|required|string|max:255',
                'email'     => 'sometimes|required|email|unique:users,email,' . $authUser->id,
                'phone'     => 'nullable|string|max:20',
                'gender'    => 'nullable|string|in:male,female,other',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }

        $authUser->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => $authUser->load('role', 'school'),
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

    // Helper function to check authorization
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