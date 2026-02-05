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
use Illuminate\Validation\ValidationException;

class SchoolController extends Controller
{
    // Define the Senior Secondary grade levels
    private $seniorSecondaryGradeLevels = ['Grade 10', 'Grade 11', 'Grade 12'];
    
    /**
     * Display a paginated listing of schools with filters and optional user statistics.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'school_type' => 'nullable|in:Primary,Secondary,Mixed',
            'curriculum' => 'nullable|in:CBC,8-4-4,Both',
            'city' => 'nullable|string|max:100',
            'has_streams' => 'nullable|in:1,0,true,false',
            'has_pre_primary' => 'nullable|in:1,0,true,false',
            'has_primary' => 'nullable|in:1,0,true,false',
            'has_junior_secondary' => 'nullable|in:1,0,true,false',
            'has_senior_secondary' => 'nullable|in:1,0,true,false',
            'has_secondary' => 'nullable|in:1,0,true,false',
            'sort_by' => 'nullable|in:name,created_at,city,school_type,code',
            'sort_order' => 'nullable|in:asc,desc',
            'include_users' => 'nullable|in:1,0,true,false',
            'include_students' => 'nullable|in:1,0,true,false',
            'include_teachers' => 'nullable|in:1,0,true,false',
        ]);

        // Convert string booleans to actual booleans
        $booleanFields = ['include_students', 'include_teachers', 'include_users', 'has_streams', 
                          'has_pre_primary', 'has_primary', 'has_junior_secondary', 
                          'has_senior_secondary', 'has_secondary'];
        
        foreach ($booleanFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = filter_var($validated[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Base query with optimized counting
        $query = School::query();

        // Conditionally eager load relationships based on request
        if (isset($validated['include_users']) && $validated['include_users']) {
            $query->withCount([
                'users',
                'users as active_users_count' => function ($q) {
                    $q->where('status', 'active');
                },
                'users as inactive_users_count' => function ($q) {
                    $q->where('status', 'inactive');
                }
            ]);
        }

        if (isset($validated['include_students']) && $validated['include_students']) {
            $query->withCount('students');
        }

        if (isset($validated['include_teachers']) && $validated['include_teachers']) {
            $query->withCount('teachers');
        }

        // Apply search filter
        if (isset($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Apply filters
        if (isset($validated['school_type'])) {
            $query->where('school_type', $validated['school_type']);
        }

        if (isset($validated['curriculum'])) {
            $curriculum = $validated['curriculum'];
            $query->where(function ($q) use ($curriculum) {
                $q->where('primary_curriculum', $curriculum)
                  ->orWhere('secondary_curriculum', $curriculum);
            });
        }

        if (isset($validated['city'])) {
            $query->where('city', $validated['city']);
        }

        if (isset($validated['has_streams'])) {
            $query->where('has_streams', $validated['has_streams']);
        }

        if (isset($validated['has_pre_primary'])) {
            $query->where('has_pre_primary', $validated['has_pre_primary']);
        }

        if (isset($validated['has_primary'])) {
            $query->where('has_primary', $validated['has_primary']);
        }

        if (isset($validated['has_junior_secondary'])) {
            $query->where('has_junior_secondary', $validated['has_junior_secondary']);
        }

        if (isset($validated['has_senior_secondary'])) {
            $query->where('has_senior_secondary', $validated['has_senior_secondary']);
        }

        if (isset($validated['has_secondary'])) {
            $query->where('has_secondary', $validated['has_secondary']);
        }

        // Apply sorting
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Paginate results
        $perPage = min($validated['per_page'] ?? 15, 100);
        $schools = $query->paginate($perPage);

        // Transform the schools data
        $schools->getCollection()->transform(function ($school) {
            $schoolData = $school->toArray();
            $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;
            $schoolData['curriculum_levels'] = $school->curriculum_levels;
            $schoolData['grade_levels'] = $school->grade_levels;
            
            // Add counts if they were loaded
            if (isset($school->students_count)) {
                $schoolData['students_count'] = $school->students_count;
            }
            if (isset($school->teachers_count)) {
                $schoolData['teachers_count'] = $school->teachers_count;
            }
            
            return $schoolData;
        });

        return response()->json([
            'message' => 'Schools fetched successfully',
            'data' => $schools->items(),
            'pagination' => [
                'total' => $schools->total(),
                'per_page' => $schools->perPage(),
                'current_page' => $schools->currentPage(),
                'last_page' => $schools->lastPage(),
                'from' => $schools->firstItem(),
                'to' => $schools->lastItem(),
            ],
            'filters_applied' => array_filter([
                'search' => $request->search,
                'school_type' => $request->school_type,
                'curriculum' => $request->curriculum,
                'city' => $request->city,
                'has_streams' => $request->has('has_streams') ? $validated['has_streams'] ?? null : null,
            ])
        ], 200);
    }

    /**
     * Get aggregated statistics for all schools.
     */
    public function statistics()
    {
        $stats = [
            'total_schools' => School::count(),
            'by_type' => School::select('school_type', DB::raw('count(*) as count'))
                ->groupBy('school_type')
                ->pluck('count', 'school_type'),
            'by_curriculum' => [
                'CBC' => School::where('primary_curriculum', 'CBC')
                    ->orWhere('secondary_curriculum', 'CBC')
                    ->count(),
                '8-4-4' => School::where('primary_curriculum', '8-4-4')
                    ->orWhere('secondary_curriculum', '8-4-4')
                    ->count(),
            ],
            'with_streams' => School::where('has_streams', true)->count(),
            'total_students' => DB::table('students')->count(),
            'total_teachers' => DB::table('teachers')->count(),
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
        ];

        return response()->json([
            'message' => 'School statistics fetched successfully',
            'data' => $stats
        ], 200);
    }

    /**
     * Get detailed user breakdown for a specific school.
     */
    public function getUserBreakdown(School $school)
    {
        $usersByRole = $school->users()
            ->select('role_id', DB::raw('count(*) as count'))
            ->groupBy('role_id')
            ->with('role:id,name')
            ->get()
            ->map(function ($item) {
                return [
                    'role' => $item->role->name ?? 'Unknown',
                    'count' => $item->count
                ];
            });

        $usersByStatus = $school->users()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'message' => 'User breakdown fetched successfully',
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
            ],
            'data' => [
                'total_users' => $school->users()->count(),
                'by_role' => $usersByRole,
                'by_status' => $usersByStatus,
                'total_students' => $school->students()->count(),
                'total_teachers' => $school->teachers()->count(),
            ]
        ], 200);
    }

    /**
     * Get a list of cities for filtering purposes.
     */
    public function getCities()
    {
        $cities = School::select('city')
            ->distinct()
            ->whereNotNull('city')
            ->orderBy('city')
            ->pluck('city');

        return response()->json([
            'message' => 'Cities fetched successfully',
            'data' => $cities
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
            'school.school_type' => 'required|in:Primary,Secondary,Mixed',
            'school.city' => 'required|string|max:100',
            'school.code' => 'required|string|max:50|unique:schools,code',
            'school.phone' => 'required|string|max:20',
            'school.email' => 'required|email|max:255',
            'school.logo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'school.primary_curriculum' => 'required|in:CBC,8-4-4,Both',
            'school.secondary_curriculum' => 'nullable|in:CBC,8-4-4,Both',
            'school.has_streams' => 'sometimes|boolean',
            'school.has_pre_primary' => 'sometimes|boolean',
            'school.has_primary' => 'sometimes|boolean',
            'school.has_junior_secondary' => 'sometimes|boolean',
            'school.has_senior_secondary' => 'sometimes|boolean',
            'school.has_secondary' => 'sometimes|boolean',
            'school.senior_secondary_pathways' => 'nullable|array',
            'school.senior_secondary_pathways.*' => 'nullable|in:STEM,Arts,Social Sciences',
            'school.grade_levels' => 'nullable|array',
            'school.grade_levels.*' => 'required|string',
            
            'admin.full_name' => 'required|string|max:255',
            'admin.email' => 'required|email|max:255|unique:users,email',
            'admin.phone' => 'required|string|max:20',
            'admin.password' => 'required|string|min:6|max:255',
            'admin.gender' => 'required|in:Male,Female,Other'
        ], [
            'school.name.unique' => 'A school with this name already exists.',
            'school.code.unique' => 'This school code is already in use.',
            'admin.email.unique' => 'This admin email is already registered.',
            'school.grade_levels.*.required' => 'Grade level cannot be empty.'
        ]);

        DB::beginTransaction();

        try {
            // 1️⃣ Handle logo upload (if present)
            $logoPath = null;
            if ($request->hasFile('school.logo')) {
                $logoPath = $request->file('school.logo')->store('logos', 'public');
            }

            // 2️⃣ Prepare grade levels
            $gradeLevels = $data['school']['grade_levels'] ?? [];
            
            // Automatically add Senior Secondary grade levels if has_senior_secondary is true
            if (isset($data['school']['has_senior_secondary']) && $data['school']['has_senior_secondary']) {
                // Merge with existing grade levels, avoiding duplicates
                $gradeLevels = array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels));
            }

            // 3️⃣ Create School
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
                'secondary_curriculum' => $data['school']['secondary_curriculum'] ?? $data['school']['primary_curriculum'],
                'has_streams' => $data['school']['has_streams'] ?? false,
                'has_pre_primary' => $data['school']['has_pre_primary'] ?? false,
                'has_primary' => $data['school']['has_primary'] ?? false,
                'has_junior_secondary' => $data['school']['has_junior_secondary'] ?? false,
                'has_senior_secondary' => $data['school']['has_senior_secondary'] ?? false,
                'has_secondary' => $data['school']['has_secondary'] ?? false,
                'senior_secondary_pathways' => $data['school']['senior_secondary_pathways'] ?? null,
                'grade_levels' => $gradeLevels,
            ]);

            // 4️⃣ Get or create admin role
            $adminRole = Role::firstOrCreate(['name' => 'admin']);

            // 5️⃣ Create Admin User
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

            // Load curriculum levels
            $schoolData = $school->toArray();
            $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;
            $schoolData['curriculum_levels'] = $school->curriculum_levels;
            $schoolData['grade_levels'] = $school->grade_levels;

            return response()->json([
                'message' => 'School and admin user created successfully',
                'school' => $schoolData,
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
        $schoolData['curriculum_levels'] = $school->curriculum_levels;
        $schoolData['grade_levels'] = $school->grade_levels;

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
        $schoolData['curriculum_levels'] = $school->curriculum_levels;
        $schoolData['grade_levels'] = $school->grade_levels;

        return response()->json([
            'message' => 'School fetched successfully',
            'data' => $schoolData
        ], 200);
    }

    /**
     * Update the specified school in storage.
     * For school admins: Only allow updates to non-locked fields
     * For super admins: Allow updates to all fields
     */
    public function update(Request $request, School $school)
    {
        $user = Auth::user();
        $isSuperAdmin = $user->role->name === 'super_admin';

        // Base validation rules - INCLUDING BOOLEAN FIELDS FOR ALL USERS
        $validationRules = [
            'name' => 'sometimes|required|string|max:255|unique:schools,name,' . $school->id,
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            // ⭐ Boolean fields available for ALL authenticated users
            'has_streams' => 'sometimes|boolean',
            'has_pre_primary' => 'sometimes|boolean',
            'has_primary' => 'sometimes|boolean',
            'has_junior_secondary' => 'sometimes|boolean',
            'has_senior_secondary' => 'sometimes|boolean',
            'has_secondary' => 'sometimes|boolean',
            'senior_secondary_pathways' => 'nullable|array',
            'senior_secondary_pathways.*' => 'nullable|in:STEM,Arts,Social Sciences',
            'grade_levels' => 'nullable|array',
            'grade_levels.*' => 'required|string'
        ];

        // Only super admins can update locked fields
        if ($isSuperAdmin) {
            $validationRules = array_merge($validationRules, [
                'school_type' => 'sometimes|required|in:Primary,Secondary,Mixed',
                'code' => 'sometimes|required|string|max:50|unique:schools,code,' . $school->id,
                'primary_curriculum' => 'sometimes|required|in:CBC,8-4-4,Both',
                'secondary_curriculum' => 'nullable|in:CBC,8-4-4,Both',
            ]);
        } else {
            // School admins can only update code if it hasn't been set yet
            if (!$school->code) {
                $validationRules['code'] = 'sometimes|required|string|max:50|unique:schools,code,' . $school->id;
            }
        }

        $data = $request->validate($validationRules, [
            'name.unique' => 'A school with this name already exists.',
            'code.unique' => 'This school code is already in use.',
            'grade_levels.*.required' => 'Grade level cannot be empty.'
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

        // Handle grade_levels correctly
        if (isset($data['grade_levels'])) {
            $gradeLevels = $data['grade_levels'];
            
            // If has_senior_secondary is being set to true, ensure Senior Secondary grade levels are included
            if (isset($data['has_senior_secondary']) && $data['has_senior_secondary']) {
                $gradeLevels = array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels));
            }
            // If has_senior_secondary is being set to false, remove Senior Secondary grade levels
            else if (isset($data['has_senior_secondary']) && !$data['has_senior_secondary']) {
                $gradeLevels = array_diff($gradeLevels, $this->seniorSecondaryGradeLevels);
            }
            // If has_senior_secondary is not being updated but was already true, ensure Senior Secondary grade levels are included
            else if (!isset($data['has_senior_secondary']) && $school->has_senior_secondary) {
                $gradeLevels = array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels));
            }
            
            $data['grade_levels'] = $gradeLevels;
        }

        // Validate curriculum consistency for super admin updates
        // Fixed: Now runs validation when ANY curriculum-related field is updated, not just school_type
        if ($isSuperAdmin && (isset($data['school_type']) || isset($data['primary_curriculum']) || isset($data['secondary_curriculum']))) {
            $this->validateCurriculumConsistency($data, $school);
        }

        // Update the school
        $school->update($data);

        // Return updated school with full logo URL
        $schoolData = $school->toArray();
        $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;
        $schoolData['curriculum_levels'] = $school->curriculum_levels;
        $schoolData['grade_levels'] = $school->grade_levels;

        return response()->json([
            'message' => 'School updated successfully',
            'data' => $schoolData,
            'updated_by_super_admin' => $isSuperAdmin
        ], 200);
    }

    /**
     * Update school by super admin - allows editing all fields including locked ones
     * This is an alternative endpoint specifically for super admin updates
     */
    public function updateBySuperAdmin(Request $request, School $school)
    {
        $user = Auth::user();
        
        if ($user->role->name !== 'super_admin') {
            return response()->json([
                'error' => 'Unauthorized. Only super admins can use this endpoint.'
            ], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:schools,name,' . $school->id,
            'school_type' => 'sometimes|required|in:Primary,Secondary,Mixed',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'code' => 'sometimes|required|string|max:50|unique:schools,code,' . $school->id,
            'logo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'primary_curriculum' => 'sometimes|required|in:CBC,8-4-4,Both',
            'secondary_curriculum' => 'nullable|in:CBC,8-4-4,Both',
            'has_streams' => 'sometimes|boolean',
            'has_pre_primary' => 'sometimes|boolean',
            'has_primary' => 'sometimes|boolean',
            'has_junior_secondary' => 'sometimes|boolean',
            'has_senior_secondary' => 'sometimes|boolean',
            'has_secondary' => 'sometimes|boolean',
            'senior_secondary_pathways' => 'nullable|array',
            'senior_secondary_pathways.*' => 'nullable|in:STEM,Arts,Social Sciences',
            'grade_levels' => 'nullable|array',
            'grade_levels.*' => 'required|string'
        ], [
            'name.unique' => 'A school with this name already exists.',
            'code.unique' => 'This school code is already in use.',
            'grade_levels.*.required' => 'Grade level cannot be empty.'
        ]);

        // Validate curriculum consistency
        $this->validateCurriculumConsistency($data, $school);

        // Handle logo update
        if ($request->hasFile('logo')) {
            // Delete old logo if it exists
            if ($school->logo && Storage::disk('public')->exists($school->logo)) {
                Storage::disk('public')->delete($school->logo);
            }

            // Store new logo
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        // Handle grade_levels correctly
        if (isset($data['grade_levels'])) {
            $gradeLevels = $data['grade_levels'];
            
            // If has_senior_secondary is being set to true, ensure Senior Secondary grade levels are included
            if (isset($data['has_senior_secondary']) && $data['has_senior_secondary']) {
                $gradeLevels = array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels));
            }
            // If has_senior_secondary is being set to false, remove Senior Secondary grade levels
            else if (isset($data['has_senior_secondary']) && !$data['has_senior_secondary']) {
                $gradeLevels = array_diff($gradeLevels, $this->seniorSecondaryGradeLevels);
            }
            // If has_senior_secondary is not being updated but was already true, ensure Senior Secondary grade levels are included
            else if (!isset($data['has_senior_secondary']) && $school->has_senior_secondary) {
                $gradeLevels = array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels));
            }
            
            $data['grade_levels'] = $gradeLevels;
        }

        $school->update($data);

        // Return updated school with full logo URL
        $schoolData = $school->toArray();
        $schoolData['logo'] = $school->logo ? asset('storage/' . $school->logo) : null;
        $schoolData['curriculum_levels'] = $school->curriculum_levels;
        $schoolData['grade_levels'] = $school->grade_levels;

        return response()->json([
            'message' => 'School updated successfully by super admin',
            'data' => $schoolData
        ], 200);
    }

    /**
     * Validate curriculum consistency when updating school structure
     */
    private function validateCurriculumConsistency(array $data, School $school)
    {
        $schoolType = $data['school_type'] ?? $school->school_type;
        $primaryCurriculum = $data['primary_curriculum'] ?? $school->primary_curriculum;
        $secondaryCurriculum = $data['secondary_curriculum'] ?? $school->secondary_curriculum;

        // Validate based on school type
        if ($schoolType === 'Primary') {
            if ($primaryCurriculum !== 'CBC') {
                throw ValidationException::withMessages([
                    'primary_curriculum' => 'Primary schools must use CBC curriculum'
                ]);
            }
            if (!empty($secondaryCurriculum)) {
                throw ValidationException::withMessages([
                    'secondary_curriculum' => 'Primary schools cannot have secondary curriculum'
                ]);
            }
        } elseif ($schoolType === 'Secondary') {
            if (!empty($primaryCurriculum)) {
                throw ValidationException::withMessages([
                    'primary_curriculum' => 'Secondary schools cannot have primary curriculum'
                ]);
            }
        } elseif ($schoolType === 'Mixed') {
            if ($primaryCurriculum !== 'CBC') {
                throw ValidationException::withMessages([
                    'primary_curriculum' => 'Mixed schools primary curriculum must be CBC'
                ]);
            }
        }
    }

    /**
     * Get schools with minimal data for dropdown/select options
     */
    public function getSchoolsForSelect()
    {
        $schools = School::select('id', 'name', 'code', 'city')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Schools fetched successfully',
            'data' => $schools
        ], 200);
    }

    /**
     * Check if a school code is available
     */
    public function checkCodeAvailability(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:50',
            'school_id' => 'nullable|exists:schools,id'
        ]);

        $query = School::where('code', $request->code);
        
        if ($request->school_id) {
            $query->where('id', '!=', $request->school_id);
        }

        $exists = $query->exists();

        return response()->json([
            'available' => !$exists,
            'message' => $exists ? 'School code is already taken' : 'School code is available'
        ], 200);
    }
}