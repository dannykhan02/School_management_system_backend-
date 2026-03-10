<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * SchoolCacheService
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Mirrors the pattern established in TeacherCacheService but applied to
 * school data caching. Built for super-admin dashboards managing 100+ schools.
 *
 * KEY STRUCTURE (hierarchical, prefix-based for easy bulk invalidation):
 *   schools:list:{filterHash}           → paginated school list (with filters)
 *   schools:single:{schoolId}           → single school record
 *   schools:statistics                  → aggregated system-wide statistics
 *   schools:cities                      → distinct cities list
 *   schools:select                      → minimal school list for dropdowns
 *   schools:{schoolId}:user-breakdown   → user breakdown for a specific school
 *
 * WHY THIS MATTERS:
 *   The index() query runs:
 *     School::withCount(['users', 'students', 'teachers'])
 *            ->where(...filters...)
 *            ->paginate(...)
 *
 *   For 100+ schools with multiple withCount() calls, each request triggers
 *   4–6 DB queries. With cache: MySQL is hit once, then Redis serves in ~3ms
 *   instead of ~600ms+. Statistics and city lists are almost never stale.
 *
 * TTL STRATEGY:
 *   - school list pages:    5 min  (any write immediately invalidates)
 *   - single school:        10 min
 *   - statistics:           10 min (tolerate slight staleness; recalculated on writes)
 *   - cities list:          1 hr   (rarely changes)
 *   - select list:          30 min
 *   - user breakdown:       5 min
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SchoolCacheService
{
    // ── TTL Constants (seconds) ───────────────────────────────────────────────
    const TTL_SCHOOL_LIST      = 300;   // 5 minutes
    const TTL_SINGLE_SCHOOL    = 600;   // 10 minutes
    const TTL_STATISTICS       = 600;   // 10 minutes
    const TTL_CITIES           = 3600;  // 1 hour
    const TTL_SELECT_LIST      = 1800;  // 30 minutes
    const TTL_USER_BREAKDOWN   = 300;   // 5 minutes

    // ── Key Builders ─────────────────────────────────────────────────────────

    /**
     * Key for a paginated, filtered school list.
     * Filters (including page + per_page) are hashed so each unique
     * combination gets its own cache entry.
     */
    public function schoolListKey(array $filters = []): string
    {
        $filterHash = empty($filters) ? 'all' : md5(serialize($filters));
        return "schools:list:{$filterHash}";
    }

    /**
     * Key for a single school's full record.
     */
    public function singleSchoolKey(int $schoolId): string
    {
        return "schools:single:{$schoolId}";
    }

    /**
     * Key for system-wide aggregated statistics.
     */
    public function statisticsKey(): string
    {
        return "schools:statistics";
    }

    /**
     * Key for the distinct cities list used in filter dropdowns.
     */
    public function citiesKey(): string
    {
        return "schools:cities";
    }

    /**
     * Key for the minimal id+name+code+city list used in select dropdowns.
     */
    public function selectListKey(): string
    {
        return "schools:select";
    }

    /**
     * Key for a single school's user/student/teacher breakdown.
     */
    public function userBreakdownKey(int $schoolId): string
    {
        return "schools:{$schoolId}:user-breakdown";
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a cached value by key.
     * Returns null on miss OR on Redis failure — always falls through to DB.
     */
    public function get(string $key): mixed
    {
        try {
            $value = Redis::get($key);
            return $value ? json_decode($value, true) : null;
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::get failed for key [{$key}]: " . $e->getMessage());
            return null;
        }
    }

    // ── SET ───────────────────────────────────────────────────────────────────

    /**
     * Store a value with a TTL. Silently fails if Redis is unavailable.
     */
    public function set(string $key, mixed $data, int $ttl): void
    {
        try {
            Redis::setex($key, $ttl, json_encode($data));
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::set failed for key [{$key}]: " . $e->getMessage());
        }
    }

    // ── INVALIDATION ─────────────────────────────────────────────────────────

    /**
     * Invalidate ALL paginated/filtered school list cache entries.
     * Called on: store(), update(), destroy().
     */
    public function invalidateSchoolList(): void
    {
        $this->deleteByPattern("schools:list:*");
    }

    /**
     * Invalidate a single school's cached record.
     * Called on: update() or destroy() for a specific school.
     */
    public function invalidateSingleSchool(int $schoolId): void
    {
        try {
            Redis::del($this->singleSchoolKey($schoolId));
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::invalidateSingleSchool failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate the system-wide statistics cache.
     * Called on: store(), destroy() — anything that changes school count.
     */
    public function invalidateStatistics(): void
    {
        try {
            Redis::del($this->statisticsKey());
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::invalidateStatistics failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate the cities dropdown cache.
     * Called on: store() or update() when city may have changed.
     */
    public function invalidateCities(): void
    {
        try {
            Redis::del($this->citiesKey());
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::invalidateCities failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate the minimal select/dropdown list.
     */
    public function invalidateSelectList(): void
    {
        try {
            Redis::del($this->selectListKey());
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::invalidateSelectList failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate user breakdown for a specific school.
     * Called on: any user/student/teacher change within a school.
     */
    public function invalidateUserBreakdown(int $schoolId): void
    {
        try {
            Redis::del($this->userBreakdownKey($schoolId));
        } catch (\Throwable $e) {
            Log::warning("SchoolCacheService::invalidateUserBreakdown failed: " . $e->getMessage());
        }
    }

    /**
     * Nuclear option — invalidate every school-related cache key.
     * Use for bulk imports, migrations, or emergency cache flush.
     */
    public function invalidateAll(): void
    {
        $this->deleteByPattern("schools:*");
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Safe pattern-based deletion using Redis SCAN (non-blocking O(1) per call).
     *
     * NEVER use KEYS in production — it is O(N) and blocks Redis for the
     * duration. SCAN pages through the keyspace in small chunks.
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
            Log::warning("SchoolCacheService::deleteByPattern failed for [{$pattern}]: " . $e->getMessage());
        }
    }
}