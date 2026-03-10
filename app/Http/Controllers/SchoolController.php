<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\User;
use App\Models\Role;
use App\Services\SchoolCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * SchoolController  v2  — Redis Caching via SchoolCacheService
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * WHAT CHANGED (v2 vs v1):
 * ─────────────────────────
 *
 * 1. REDIS CACHING  (the main addition)
 *    Every read endpoint now checks Redis before hitting MySQL.
 *    Every write endpoint invalidates relevant cache keys after committing.
 *
 *    Cache keys used:
 *      schools:list:{hash}              → paginated list (per page + filter combo)
 *      schools:single:{id}              → single school record
 *      schools:statistics               → aggregated stats
 *      schools:cities                   → distinct city list
 *      schools:select                   → minimal dropdown list
 *      schools:{id}:user-breakdown      → per-school user stats
 *
 * 2. PAGINATION  (already existed, now cached per-page)
 *    index() supports:
 *      GET /api/schools/all?page=1&per_page=15&search=...
 *    Each unique filter+page+per_page combination is cached separately.
 *    On any write the entire list cache is wiped (all pages, all filters).
 *
 * 3. CACHE DEBUG HEADER
 *    All cached endpoints return `"_cache": "hit"` or `"_cache": "miss"`
 *    so you can verify cache behaviour in your API client.
 *
 * 4. INVALIDATION STRATEGY
 *    - store()                → invalidates list, statistics, cities, select
 *    - update()               → invalidates list, single, cities, select
 *    - updateBySuperAdmin()   → same as update()
 *    - (no destroy endpoint, add when needed — pattern is identical)
 *
 * UNCHANGED:
 *    All validation rules, business logic, curriculum consistency checks,
 *    logo handling, grade level auto-population, and response shapes are
 *    identical to v1. Only I/O layer (cache read/write) was added.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SchoolController extends Controller
{
    // Senior Secondary grade levels auto-added when has_senior_secondary = true
    private $seniorSecondaryGradeLevels = ['Grade 10', 'Grade 11', 'Grade 12'];

    protected SchoolCacheService $cache;

    public function __construct(SchoolCacheService $cache)
    {
        $this->cache = $cache;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Shape a School model into the standard API response array.
     * Centralised here so every endpoint returns the same structure.
     */
    private function shapeSchool(School $school, array $extras = []): array
    {
        $data = $school->toArray();
        $data['logo']              = $school->logo ? asset('storage/' . $school->logo) : null;
        $data['curriculum_levels'] = $school->curriculum_levels;
        $data['grade_levels']      = $school->grade_levels;

        return array_merge($data, $extras);
    }

    /**
     * Apply has_senior_secondary logic to a grade_levels array.
     * Shared by store(), update(), and updateBySuperAdmin().
     */
    private function resolveGradeLevels(array $gradeLevels, ?bool $hasSeniorSecondary, ?bool $existingHasSeniorSecondary = null): array
    {
        if ($hasSeniorSecondary === true) {
            // Merging ensures no duplicates
            return array_values(array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels)));
        }

        if ($hasSeniorSecondary === false) {
            return array_values(array_diff($gradeLevels, $this->seniorSecondaryGradeLevels));
        }

        // has_senior_secondary not in this request but was already true on the model
        if ($existingHasSeniorSecondary === true) {
            return array_values(array_unique(array_merge($gradeLevels, $this->seniorSecondaryGradeLevels)));
        }

        return $gradeLevels;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/all  — Paginated list with Redis cache
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Display a paginated, filterable listing of schools.
     *
     * Supports the following query parameters:
     *   page, per_page, search, school_type, curriculum, city,
     *   has_streams, has_pre_primary, has_primary, has_junior_secondary,
     *   has_senior_secondary, has_secondary, sort_by, sort_order,
     *   include_users, include_students, include_teachers
     *
     * Cache: each unique combination of filters+page+per_page is cached
     *        separately under schools:list:{md5(filters)}.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page'              => 'nullable|integer|min:1|max:100',
            'page'                  => 'nullable|integer|min:1',
            'search'                => 'nullable|string|max:255',
            'school_type'           => 'nullable|in:Primary,Secondary,Mixed',
            'curriculum'            => 'nullable|in:CBC,8-4-4,Both',
            'city'                  => 'nullable|string|max:100',
            'has_streams'           => 'nullable|in:1,0,true,false',
            'has_pre_primary'       => 'nullable|in:1,0,true,false',
            'has_primary'           => 'nullable|in:1,0,true,false',
            'has_junior_secondary'  => 'nullable|in:1,0,true,false',
            'has_senior_secondary'  => 'nullable|in:1,0,true,false',
            'has_secondary'         => 'nullable|in:1,0,true,false',
            'sort_by'               => 'nullable|in:name,created_at,city,school_type,code',
            'sort_order'            => 'nullable|in:asc,desc',
            'include_users'         => 'nullable|in:1,0,true,false',
            'include_students'      => 'nullable|in:1,0,true,false',
            'include_teachers'      => 'nullable|in:1,0,true,false',
        ]);

        // Normalise string booleans → actual booleans
        $booleanFields = [
            'include_students', 'include_teachers', 'include_users', 'has_streams',
            'has_pre_primary', 'has_primary', 'has_junior_secondary',
            'has_senior_secondary', 'has_secondary',
        ];
        foreach ($booleanFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = filter_var($validated[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $perPage = min((int) ($validated['per_page'] ?? 15), 100);
        $page    = max(1, (int) ($validated['page'] ?? 1));

        // Build cache key from all filter inputs (including pagination)
        $cacheFilters = array_filter(array_merge($validated, [
            'page'     => $page,
            'per_page' => $perPage,
        ]), fn($v) => $v !== null && $v !== '');

        $cacheKey = $this->cache->schoolListKey($cacheFilters);
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, ['_cache' => 'hit']), 200);
        }

        // ── Cache miss: build from DB ─────────────────────────────────────────
        $query = School::query();

        // Conditionally eager-load user/student/teacher counts
        if (!empty($validated['include_users'])) {
            $query->withCount([
                'users',
                'users as active_users_count'   => fn($q) => $q->where('status', 'active'),
                'users as inactive_users_count' => fn($q) => $q->where('status', 'inactive'),
            ]);
        }
        if (!empty($validated['include_students'])) {
            $query->withCount('students');
        }
        if (!empty($validated['include_teachers'])) {
            $query->withCount('teachers');
        }

        // Search
        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name',  'like', "%{$search}%")
                  ->orWhere('code',  'like', "%{$search}%")
                  ->orWhere('city',  'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Scalar filters
        if (!empty($validated['school_type'])) $query->where('school_type', $validated['school_type']);
        if (!empty($validated['city']))         $query->where('city', $validated['city']);

        // Boolean filters
        $boolFilters = ['has_streams', 'has_pre_primary', 'has_primary', 'has_junior_secondary', 'has_senior_secondary', 'has_secondary'];
        foreach ($boolFilters as $bf) {
            if (isset($validated[$bf])) $query->where($bf, $validated[$bf]);
        }

        // Curriculum — matches either primary or secondary curriculum column
        if (!empty($validated['curriculum'])) {
            $curriculum = $validated['curriculum'];
            $query->where(function ($q) use ($curriculum) {
                $q->where('primary_curriculum', $curriculum)
                  ->orWhere('secondary_curriculum', $curriculum);
            });
        }

        // Sorting & pagination
        $sortBy    = $validated['sort_by']    ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        // Shape each school
        $items = collect($paginated->items())->map(function ($school) use ($validated) {
            $shaped = $this->shapeSchool($school);

            // Only include counts if they were loaded
            if (isset($school->students_count)) $shaped['students_count'] = $school->students_count;
            if (isset($school->teachers_count)) $shaped['teachers_count'] = $school->teachers_count;
            if (isset($school->users_count))    $shaped['users_count']    = $school->users_count;

            return $shaped;
        })->values()->toArray();

        $payload = [
            'message' => 'Schools fetched successfully',
            'data'    => $items,
            'pagination' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'from'         => $paginated->firstItem() ?? 0,
                'to'           => $paginated->lastItem()  ?? 0,
                'has_more_pages' => $paginated->hasMorePages(),
            ],
            'filters_applied' => array_filter([
                'search'      => $request->search,
                'school_type' => $request->school_type,
                'curriculum'  => $request->curriculum,
                'city'        => $request->city,
                'has_streams' => $request->has('has_streams') ? ($validated['has_streams'] ?? null) : null,
            ]),
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_SCHOOL_LIST);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/statistics  — Aggregated stats (cached)
    // ──────────────────────────────────────────────────────────────────────────

    public function statistics()
    {
        $cacheKey = $this->cache->statisticsKey();
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, ['_cache' => 'hit']), 200);
        }

        $stats = [
            'total_schools'  => School::count(),
            'by_type'        => School::select('school_type', DB::raw('count(*) as count'))
                ->groupBy('school_type')
                ->pluck('count', 'school_type'),
            'by_curriculum'  => [
                'CBC'   => School::where('primary_curriculum', 'CBC')
                    ->orWhere('secondary_curriculum', 'CBC')
                    ->count(),
                '8-4-4' => School::where('primary_curriculum', '8-4-4')
                    ->orWhere('secondary_curriculum', '8-4-4')
                    ->count(),
            ],
            'with_streams'   => School::where('has_streams', true)->count(),
            'total_students' => DB::table('students')->count(),
            'total_teachers' => DB::table('teachers')->count(),
            'total_users'    => User::count(),
            'active_users'   => User::where('status', 'active')->count(),
        ];

        $payload = [
            'message' => 'School statistics fetched successfully',
            'data'    => $stats,
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_STATISTICS);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/{school}/user-breakdown  — Per-school stats (cached)
    // ──────────────────────────────────────────────────────────────────────────

    public function getUserBreakdown(School $school)
    {
        $cacheKey = $this->cache->userBreakdownKey($school->id);
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, ['_cache' => 'hit']), 200);
        }

        $usersByRole = $school->users()
            ->select('role_id', DB::raw('count(*) as count'))
            ->groupBy('role_id')
            ->with('role:id,name')
            ->get()
            ->map(fn($item) => [
                'role'  => $item->role->name ?? 'Unknown',
                'count' => $item->count,
            ]);

        $usersByStatus = $school->users()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $payload = [
            'message' => 'User breakdown fetched successfully',
            'school'  => [
                'id'   => $school->id,
                'name' => $school->name,
            ],
            'data' => [
                'total_users'    => $school->users()->count(),
                'by_role'        => $usersByRole,
                'by_status'      => $usersByStatus,
                'total_students' => $school->students()->count(),
                'total_teachers' => $school->teachers()->count(),
            ],
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_USER_BREAKDOWN);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/cities  — Distinct cities for filter dropdowns (cached)
    // ──────────────────────────────────────────────────────────────────────────

    public function getCities()
    {
        $cacheKey = $this->cache->citiesKey();
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, ['_cache' => 'hit']), 200);
        }

        $cities = School::select('city')
            ->distinct()
            ->whereNotNull('city')
            ->orderBy('city')
            ->pluck('city');

        $payload = [
            'message' => 'Cities fetched successfully',
            'data'    => $cities,
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_CITIES);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/select  — Minimal list for dropdowns (cached)
    // ──────────────────────────────────────────────────────────────────────────

    public function getSchoolsForSelect()
    {
        $cacheKey = $this->cache->selectListKey();
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, ['_cache' => 'hit']), 200);
        }

        $schools = School::select('id', 'name', 'code', 'city')
            ->orderBy('name')
            ->get();

        $payload = [
            'message' => 'Schools fetched successfully',
            'data'    => $schools,
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_SELECT_LIST);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/{school}  — Single school record (cached)
    // ──────────────────────────────────────────────────────────────────────────

    public function show(School $school)
    {
        $cacheKey = $this->cache->singleSchoolKey($school->id);
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, ['_cache' => 'hit']), 200);
        }

        $payload = [
            'message' => 'School fetched successfully',
            'data'    => $this->shapeSchool($school),
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_SINGLE_SCHOOL);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/my-school  — School for the current authenticated user
    // (Not cached — always reads live auth session)
    // ──────────────────────────────────────────────────────────────────────────

    public function mySchool()
    {
        $user = Auth::user();

        if (!$user || !$user->school) {
            return response()->json(['error' => 'No school found for this user'], 404);
        }

        $school = $user->school;

        // Try the single-school cache first — data is the same
        $cacheKey = $this->cache->singleSchoolKey($school->id);
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge([
                'message' => 'School fetched successfully',
                'data'    => $cached['data'] ?? $cached,
            ], ['_cache' => 'hit']), 200);
        }

        $payload = [
            'message' => 'School fetched successfully',
            'data'    => $this->shapeSchool($school),
        ];

        $this->cache->set($cacheKey, $payload, SchoolCacheService::TTL_SINGLE_SCHOOL);

        return response()->json(array_merge($payload, ['_cache' => 'miss']), 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/schools/check-code  — Code availability check (no cache needed)
    // ──────────────────────────────────────────────────────────────────────────

    public function checkCodeAvailability(Request $request)
    {
        $request->validate([
            'code'      => 'required|string|max:50',
            'school_id' => 'nullable|exists:schools,id',
        ]);

        $query = School::where('code', $request->code);
        if ($request->school_id) {
            $query->where('id', '!=', $request->school_id);
        }
        $exists = $query->exists();

        return response()->json([
            'available' => !$exists,
            'message'   => $exists ? 'School code is already taken' : 'School code is available',
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/schools  — Create school + admin user
    // Invalidates: list, statistics, cities, select
    // ──────────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'school.name'                        => 'required|string|max:255|unique:schools,name',
            'school.address'                     => 'required|string|max:500',
            'school.school_type'                 => 'required|in:Primary,Secondary,Mixed',
            'school.city'                        => 'required|string|max:100',
            'school.code'                        => 'required|string|max:50|unique:schools,code',
            'school.phone'                       => 'required|string|max:20',
            'school.email'                       => 'required|email|max:255',
            'school.logo'                        => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'school.primary_curriculum'          => 'required|in:CBC,8-4-4,Both',
            'school.secondary_curriculum'        => 'nullable|in:CBC,8-4-4,Both',
            'school.has_streams'                 => 'sometimes|boolean',
            'school.has_pre_primary'             => 'sometimes|boolean',
            'school.has_primary'                 => 'sometimes|boolean',
            'school.has_junior_secondary'        => 'sometimes|boolean',
            'school.has_senior_secondary'        => 'sometimes|boolean',
            'school.has_secondary'               => 'sometimes|boolean',
            'school.senior_secondary_pathways'   => 'nullable|array',
            'school.senior_secondary_pathways.*' => 'nullable|in:STEM,Arts,Social Sciences',
            'school.grade_levels'                => 'nullable|array',
            'school.grade_levels.*'              => 'required|string',

            'admin.full_name' => 'required|string|max:255',
            'admin.email'     => 'required|email|max:255|unique:users,email',
            'admin.phone'     => 'required|string|max:20',
            'admin.password'  => 'required|string|min:6|max:255',
            'admin.gender'    => 'required|in:Male,Female,Other',
        ], [
            'school.name.unique'          => 'A school with this name already exists.',
            'school.code.unique'          => 'This school code is already in use.',
            'admin.email.unique'          => 'This admin email is already registered.',
            'school.grade_levels.*.required' => 'Grade level cannot be empty.',
        ]);

        DB::beginTransaction();

        try {
            // Logo upload
            $logoPath = null;
            if ($request->hasFile('school.logo')) {
                $logoPath = $request->file('school.logo')->store('logos', 'public');
            }

            // Grade levels with senior secondary auto-population
            $hasSeniorSecondary = $data['school']['has_senior_secondary'] ?? false;
            $gradeLevels = $this->resolveGradeLevels(
                $data['school']['grade_levels'] ?? [],
                $hasSeniorSecondary
            );

            $school = School::create([
                'name'                      => $data['school']['name'],
                'address'                   => $data['school']['address'],
                'school_type'               => $data['school']['school_type'],
                'city'                      => $data['school']['city'],
                'code'                      => $data['school']['code'],
                'phone'                     => $data['school']['phone'],
                'email'                     => $data['school']['email'],
                'logo'                      => $logoPath,
                'primary_curriculum'        => $data['school']['primary_curriculum'],
                'secondary_curriculum'      => $data['school']['secondary_curriculum'] ?? $data['school']['primary_curriculum'],
                'has_streams'               => $data['school']['has_streams']            ?? false,
                'has_pre_primary'           => $data['school']['has_pre_primary']        ?? false,
                'has_primary'               => $data['school']['has_primary']            ?? false,
                'has_junior_secondary'      => $data['school']['has_junior_secondary']   ?? false,
                'has_senior_secondary'      => $hasSeniorSecondary,
                'has_secondary'             => $data['school']['has_secondary']          ?? false,
                'senior_secondary_pathways' => $data['school']['senior_secondary_pathways'] ?? null,
                'grade_levels'              => $gradeLevels,
            ]);

            $adminRole = Role::firstOrCreate(['name' => 'admin']);

            $user = User::create([
                'school_id' => $school->id,
                'role_id'   => $adminRole->id,
                'full_name' => $data['admin']['full_name'],
                'email'     => $data['admin']['email'],
                'phone'     => $data['admin']['phone'] ?? null,
                'password'  => Hash::make($data['admin']['password']),
                'gender'    => $data['admin']['gender'] ?? null,
                'status'    => 'active',
            ]);

            DB::commit();

            // ★ Invalidate all list/stats/cities/select caches
            $this->cache->invalidateSchoolList();
            $this->cache->invalidateStatistics();
            $this->cache->invalidateCities();
            $this->cache->invalidateSelectList();

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create school: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message'  => 'School and admin user created successfully',
            'school'   => $this->shapeSchool($school),
            'admin'    => $user,
            'logo_url' => $logoPath ? asset('storage/' . $logoPath) : null,
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT/PATCH /api/schools/{school}  — Update school
    // Invalidates: list, single, cities, select
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * For school admins: restricted to non-locked fields.
     * For super admins: all fields including school_type, code, curriculum.
     */
    public function update(Request $request, School $school)
    {
        $user          = Auth::user();
        $isSuperAdmin  = $user->role->name === 'super_admin';

        $validationRules = [
            'name'                        => 'sometimes|required|string|max:255|unique:schools,name,' . $school->id,
            'address'                     => 'nullable|string|max:500',
            'city'                        => 'nullable|string|max:100',
            'phone'                       => 'nullable|string|max:20',
            'email'                       => 'nullable|email|max:255',
            'logo'                        => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'has_streams'                 => 'sometimes|boolean',
            'has_pre_primary'             => 'sometimes|boolean',
            'has_primary'                 => 'sometimes|boolean',
            'has_junior_secondary'        => 'sometimes|boolean',
            'has_senior_secondary'        => 'sometimes|boolean',
            'has_secondary'               => 'sometimes|boolean',
            'senior_secondary_pathways'   => 'nullable|array',
            'senior_secondary_pathways.*' => 'nullable|in:STEM,Arts,Social Sciences',
            'grade_levels'                => 'nullable|array',
            'grade_levels.*'              => 'required|string',
        ];

        if ($isSuperAdmin) {
            $validationRules = array_merge($validationRules, [
                'school_type'          => 'sometimes|required|in:Primary,Secondary,Mixed',
                'code'                 => 'sometimes|required|string|max:50|unique:schools,code,' . $school->id,
                'primary_curriculum'   => 'sometimes|required|in:CBC,8-4-4,Both',
                'secondary_curriculum' => 'nullable|in:CBC,8-4-4,Both',
            ]);
        } elseif (!$school->code) {
            // School admins can set the code only if it was never set
            $validationRules['code'] = 'sometimes|required|string|max:50|unique:schools,code,' . $school->id;
        }

        $data = $request->validate($validationRules, [
            'name.unique'               => 'A school with this name already exists.',
            'code.unique'               => 'This school code is already in use.',
            'grade_levels.*.required'   => 'Grade level cannot be empty.',
        ]);

        // Logo replacement
        if ($request->hasFile('logo')) {
            if ($school->logo && Storage::disk('public')->exists($school->logo)) {
                Storage::disk('public')->delete($school->logo);
            }
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        // Grade levels
        if (isset($data['grade_levels'])) {
            $hasSeniorInRequest  = $data['has_senior_secondary'] ?? null;
            $existingHasSenior   = $school->has_senior_secondary;
            $data['grade_levels'] = $this->resolveGradeLevels(
                $data['grade_levels'],
                $hasSeniorInRequest !== null ? (bool) $hasSeniorInRequest : null,
                $existingHasSenior
            );
        }

        // Curriculum consistency (super admin only)
        if ($isSuperAdmin && (isset($data['school_type']) || isset($data['primary_curriculum']) || isset($data['secondary_curriculum']))) {
            $this->validateCurriculumConsistency($data, $school);
        }

        $school->update($data);

        // ★ Invalidate caches
        $this->cache->invalidateSchoolList();
        $this->cache->invalidateSingleSchool($school->id);
        $this->cache->invalidateCities();
        $this->cache->invalidateSelectList();

        return response()->json([
            'message'               => 'School updated successfully',
            'data'                  => $this->shapeSchool($school->fresh()),
            'updated_by_super_admin' => $isSuperAdmin,
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT /api/schools/{school}/super-admin  — Super-admin only update endpoint
    // Invalidates: list, single, cities, select
    // ──────────────────────────────────────────────────────────────────────────

    public function updateBySuperAdmin(Request $request, School $school)
    {
        $user = Auth::user();

        if ($user->role->name !== 'super_admin') {
            return response()->json(['error' => 'Unauthorized. Only super admins can use this endpoint.'], 403);
        }

        $data = $request->validate([
            'name'                        => 'sometimes|required|string|max:255|unique:schools,name,' . $school->id,
            'school_type'                 => 'sometimes|required|in:Primary,Secondary,Mixed',
            'address'                     => 'nullable|string|max:500',
            'city'                        => 'nullable|string|max:100',
            'phone'                       => 'nullable|string|max:20',
            'email'                       => 'nullable|email|max:255',
            'code'                        => 'sometimes|required|string|max:50|unique:schools,code,' . $school->id,
            'logo'                        => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'primary_curriculum'          => 'sometimes|required|in:CBC,8-4-4,Both',
            'secondary_curriculum'        => 'nullable|in:CBC,8-4-4,Both',
            'has_streams'                 => 'sometimes|boolean',
            'has_pre_primary'             => 'sometimes|boolean',
            'has_primary'                 => 'sometimes|boolean',
            'has_junior_secondary'        => 'sometimes|boolean',
            'has_senior_secondary'        => 'sometimes|boolean',
            'has_secondary'               => 'sometimes|boolean',
            'senior_secondary_pathways'   => 'nullable|array',
            'senior_secondary_pathways.*' => 'nullable|in:STEM,Arts,Social Sciences',
            'grade_levels'                => 'nullable|array',
            'grade_levels.*'              => 'required|string',
        ], [
            'name.unique'               => 'A school with this name already exists.',
            'code.unique'               => 'This school code is already in use.',
            'grade_levels.*.required'   => 'Grade level cannot be empty.',
        ]);

        $this->validateCurriculumConsistency($data, $school);

        // Logo replacement
        if ($request->hasFile('logo')) {
            if ($school->logo && Storage::disk('public')->exists($school->logo)) {
                Storage::disk('public')->delete($school->logo);
            }
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        // Grade levels
        if (isset($data['grade_levels'])) {
            $hasSeniorInRequest   = $data['has_senior_secondary'] ?? null;
            $existingHasSenior    = $school->has_senior_secondary;
            $data['grade_levels'] = $this->resolveGradeLevels(
                $data['grade_levels'],
                $hasSeniorInRequest !== null ? (bool) $hasSeniorInRequest : null,
                $existingHasSenior
            );
        }

        $school->update($data);

        // ★ Invalidate caches
        $this->cache->invalidateSchoolList();
        $this->cache->invalidateSingleSchool($school->id);
        $this->cache->invalidateCities();
        $this->cache->invalidateSelectList();

        return response()->json([
            'message' => 'School updated successfully by super admin',
            'data'    => $this->shapeSchool($school->fresh()),
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE — Curriculum consistency guard
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validates that the submitted curriculum fields are consistent with
     * the school type being applied (or already on the model).
     *
     * Throws ValidationException on failure so Laravel's error handling
     * converts it to a 422 JSON response automatically.
     */
    private function validateCurriculumConsistency(array $data, School $school): void
    {
        $schoolType          = $data['school_type']          ?? $school->school_type;
        $primaryCurriculum   = $data['primary_curriculum']   ?? $school->primary_curriculum;
        $secondaryCurriculum = $data['secondary_curriculum'] ?? $school->secondary_curriculum;

        if ($schoolType === 'Primary') {
            if ($primaryCurriculum !== 'CBC') {
                throw ValidationException::withMessages([
                    'primary_curriculum' => 'Primary schools must use CBC curriculum',
                ]);
            }
            if (!empty($secondaryCurriculum)) {
                throw ValidationException::withMessages([
                    'secondary_curriculum' => 'Primary schools cannot have secondary curriculum',
                ]);
            }
        } elseif ($schoolType === 'Secondary') {
            if (!empty($primaryCurriculum)) {
                throw ValidationException::withMessages([
                    'primary_curriculum' => 'Secondary schools cannot have primary curriculum',
                ]);
            }
        } elseif ($schoolType === 'Mixed') {
            if ($primaryCurriculum !== 'CBC') {
                throw ValidationException::withMessages([
                    'primary_curriculum' => 'Mixed schools primary curriculum must be CBC',
                ]);
            }
        }
    }
}