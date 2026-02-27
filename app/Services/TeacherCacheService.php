<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * TeacherCacheService
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Mirrors the pattern established in RedisTokenService (used in AuthController)
 * but applies it to teacher/school data caching.
 *
 * KEY STRUCTURE (hierarchical, easy to invalidate by prefix):
 *   school:{schoolId}:teachers              → all teachers list for a school
 *   school:{schoolId}:teacher:{id}          → single teacher record
 *   school:{schoolId}:combinations          → combination dropdown data
 *   school:{schoolId}:combination:{id}:preview → Phase 2 preview data
 *   school:{schoolId}:subjects              → subjects list
 *
 * WHY THIS MATTERS:
 *   Your index() query currently does:
 *     Teacher::with(['user','school','combination','classTeacherStreams',
 *                    'teachingStreams','qualifiedSubjects'])
 *              ->where('school_id', $schoolId)->get()
 *
 *   For 30 teachers that's ~8 JOIN queries hitting MySQL every single request.
 *   With cache: MySQL is hit ONCE, then Redis serves all subsequent requests
 *   in ~5ms instead of ~800ms.
 *
 * TTL STRATEGY:
 *   - teachers list:    5 min  (admin edits invalidate immediately anyway)
 *   - single teacher:   10 min
 *   - combinations:     24 hrs (almost never change)
 *   - combo preview:    1 hr   (school subjects rarely change)
 *   - subjects list:    30 min
 * ─────────────────────────────────────────────────────────────────────────────
 */
class TeacherCacheService
{
    // ── TTL Constants (seconds) ───────────────────────────────────────────────
    const TTL_TEACHERS_LIST     = 300;    // 5 minutes
    const TTL_SINGLE_TEACHER    = 600;    // 10 minutes
    const TTL_COMBINATIONS      = 86400;  // 24 hours
    const TTL_COMBO_PREVIEW     = 3600;   // 1 hour
    const TTL_SUBJECTS          = 1800;   // 30 minutes

    // ── Key Builders ─────────────────────────────────────────────────────────

    public function teachersListKey(int $schoolId, array $filters = []): string
    {
        // Include filters in key so filtered results are cached separately
        $filterHash = empty($filters) ? 'all' : md5(serialize($filters));
        return "school:{$schoolId}:teachers:{$filterHash}";
    }

    public function singleTeacherKey(int $schoolId, int $teacherId): string
    {
        return "school:{$schoolId}:teacher:{$teacherId}";
    }

    public function combinationsKey(int $schoolId, array $filters = []): string
    {
        $filterHash = empty($filters) ? 'all' : md5(serialize($filters));
        return "school:{$schoolId}:combinations:{$filterHash}";
    }

    public function combinationPreviewKey(int $schoolId, int $combinationId): string
    {
        return "school:{$schoolId}:combination:{$combinationId}:preview";
    }

    public function subjectsKey(int $schoolId, array $filters = []): string
    {
        $filterHash = empty($filters) ? 'all' : md5(serialize($filters));
        return "school:{$schoolId}:subjects:{$filterHash}";
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    /**
     * Get a cached value. Returns null on cache miss or Redis error.
     */
    public function get(string $key): mixed
    {
        try {
            $value = Redis::get($key);
            return $value ? json_decode($value, true) : null;
        } catch (\Throwable $e) {
            // CRITICAL: Never let cache failures break the app.
            // Log it and return null so the controller falls through to DB.
            Log::warning("TeacherCacheService::get failed for key [{$key}]: " . $e->getMessage());
            return null;
        }
    }

    // ── SET ───────────────────────────────────────────────────────────────────

    /**
     * Store a value with TTL. Silently fails if Redis is unavailable.
     */
    public function set(string $key, mixed $data, int $ttl): void
    {
        try {
            Redis::setex($key, $ttl, json_encode($data));
        } catch (\Throwable $e) {
            Log::warning("TeacherCacheService::set failed for key [{$key}]: " . $e->getMessage());
        }
    }

    // ── INVALIDATION ─────────────────────────────────────────────────────────

    /**
     * Invalidate ALL teacher-related cache for a school.
     * Called on: store(), update(), destroy()
     *
     * Uses Redis SCAN (not KEYS) to avoid blocking on large keysets.
     */
    public function invalidateSchoolTeachers(int $schoolId): void
    {
        $this->deleteByPattern("school:{$schoolId}:teacher*");
    }

    /**
     * Invalidate a single teacher's cached record.
     * Called on: update() for a specific teacher.
     */
    public function invalidateSingleTeacher(int $schoolId, int $teacherId): void
    {
        try {
            Redis::del($this->singleTeacherKey($schoolId, $teacherId));
        } catch (\Throwable $e) {
            Log::warning("TeacherCacheService::invalidateSingleTeacher failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate combination preview cache for a school.
     * Called when: school subjects are updated (rare).
     */
    public function invalidateCombinationPreviews(int $schoolId): void
    {
        $this->deleteByPattern("school:{$schoolId}:combination*:preview");
    }

    /**
     * Invalidate subjects cache for a school.
     */
    public function invalidateSubjects(int $schoolId): void
    {
        $this->deleteByPattern("school:{$schoolId}:subjects:*");
    }

    /**
     * Nuclear option — wipe everything for a school.
     * Use when: school settings change, bulk import, etc.
     */
    public function invalidateAll(int $schoolId): void
    {
        $this->deleteByPattern("school:{$schoolId}:*");
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Safe pattern-based delete using SCAN (non-blocking).
     * KEYS command is O(N) and blocks Redis — never use it in production.
     */
    private function deleteByPattern(string $pattern): void
    {
        try {
            $cursor = '0';
            do {
                [$cursor, $keys] = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
                if (!empty($keys)) {
                    Redis::del(...$keys);
                }
            } while ($cursor !== '0');
        } catch (\Throwable $e) {
            Log::warning("TeacherCacheService::deleteByPattern failed for [{$pattern}]: " . $e->getMessage());
        }
    }
}