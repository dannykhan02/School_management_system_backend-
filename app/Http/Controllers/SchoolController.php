<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class SchoolController extends Controller
{
    /**
     * Display a listing of the schools.
     */
    public function index()
    {
        // Fetch all schools with their users
        $schools = School::with('users')->get();

        // Map each school to include full logo URL
        $schools = $schools->map(function($school) {
            $schoolData = $school->toArray();
            $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;
            return $schoolData;
        });

        return response()->json([
            'message' => 'All registered schools fetched successfully',
            'data' => $schools
        ], 200);
    }

    /**
     * Store a newly created school and its admin user in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'school.name' => 'required|string|max:255|unique:schools,name',
            'school.address' => 'required|string|max:500',
            'school.school_type' => 'required|in:Primary,Secondary',
            'school.city' => 'required|string|max:100',
            'school.code' => 'required|string|max:50|unique:schools,code',
            'school.phone' => 'required|string|max:20',
            'school.email' => 'required|email|max:255',
            'school.logo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'school.primary_curriculum' => 'required|in:CBC,8-4-4,Both',
            'school.has_streams' => 'sometimes|boolean',
            
            'admin.full_name' => 'required|string|max:255',
            'admin.email' => 'required|email|max:255|unique:users,email',
            'admin.phone' => 'required|string|max:20',
            'admin.password' => 'required|string|min:6|max:255',
            'admin.gender' => 'required|in:Male,Female,Other'
        ], [
            'school.name.unique' => 'A school with this name already exists.',
            'school.code.unique' => 'This school code is already in use.',
            'admin.email.unique' => 'This admin email is already registered.'
        ]);

        DB::beginTransaction();

        try {
            // 1️⃣ Handle logo upload (if present)
            $logoPath = null;
            if ($request->hasFile('school.logo')) {
                $logoPath = $request->file('school.logo')->store('logos', 'public');
            }

            // 2️⃣ Create School
            $school = School::create([
                'name' => $data['school']['name'],
                'address' => $data['school']['address'] ?? null,
                'school_type' => $data['school']['school_type'] ?? null,
                'city' => $data['school']['city'] ?? null,
                'code' => $data['school']['code'] ?? null,
                'phone' => $data['school']['phone'] ?? null,
                'email' => $data['school']['email'] ?? null,
                'logo' => $logoPath,
                'primary_curriculum' => $data['school']['primary_curriculum'],
                'has_streams' => $data['school']['has_streams'] ?? false,
            ]);

            // 3️⃣ Get or create admin role
            $adminRole = Role::firstOrCreate(['name' => 'admin']);

            // 4️⃣ Create Admin User
            $user = User::create([
                'school_id' => $school->id,
                'role_id' => $adminRole->id,
                'full_name' => $data['admin']['full_name'],
                'email' => $data['admin']['email'],
                'phone' => $data['admin']['phone'] ?? null,
                'password' => Hash::make($data['admin']['password']),
                'gender' => $data['admin']['gender'] ?? null,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'School and admin user created successfully',
                'school' => $school,
                'admin' => $user,
                'logo_url' => $logoPath ? asset('storage/' . $logoPath) : null
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create school: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified school.
     */
    public function show(School $school)
    {
        $schoolData = $school->toArray();
        $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;

        return response()->json([
            'message' => 'School fetched successfully',
            'data' => $schoolData
        ], 200);
    }

    /**
     * Get the school for the currently authenticated user.
     */
    public function mySchool()
    {
        $user = Auth::user();

        if (!$user || !$user->school) {
            return response()->json([
                'error' => 'No school found for this user'
            ], 404);
        }

        $school = $user->school;

        $schoolData = $school->toArray();
        $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;

        return response()->json([
            'message' => 'School fetched successfully',
            'data' => $schoolData
        ], 200);
    }

    /**
     * Update the specified school in storage.
     */
    public function update(Request $request, School $school)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:schools,name,' . $school->id,
            'address' => 'nullable|string|max:500',
            'school_type' => 'nullable|in:Primary,Secondary',
            'city' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:50|unique:schools,code,' . $school->id,
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'primary_curriculum' => 'sometimes|required|in:CBC,8-4-4,Both',
            'has_streams' => 'sometimes|boolean'
        ], [
            'name.unique' => 'A school with this name already exists.',
            'code.unique' => 'This school code is already in use.',
        ]);

        // Handle logo update
        if ($request->hasFile('logo')) {
            // Delete old logo if it exists
            if ($school->logo && Storage::disk('public')->exists($school->logo)) {
                Storage::disk('public')->delete($school->logo);
            }

            // Store new logo
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $school->update($data);

        // Return updated school with full logo URL
        $schoolData = $school->toArray();
        $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;

        return response()->json([
            'message' => 'School updated successfully',
            'data' => $schoolData
        ], 200);
    }
}