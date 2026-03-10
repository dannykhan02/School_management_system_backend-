<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');

            // ── Core toggle ──────────────────────────────────────────────────────
            // false  → school does not use admission numbers at all
            $table->boolean('enabled')->default(true);

            // ── Pattern engine ───────────────────────────────────────────────────
            // Template string using tokens: {PREFIX} {SEP} {YEAR} {NUMBER}
            // Examples:
            //   "{NUMBER}"                → 0001
            //   "{YEAR}/{NUMBER}"         → 2025/001
            //   "{PREFIX}/{YEAR}/{NUMBER}"→ KHS/2025/001
            //   "{PREFIX}{SEP}{NUMBER}"   → KHS-0001
            $table->string('pattern')->default('{NUMBER}');

            // ── Pattern components ────────────────────────────────────────────────
            $table->string('prefix')->nullable();       // "KHS", "ADM", "GHS" — null if not used
            $table->string('separator')->default('/');  // "/", "-", ""
            $table->boolean('include_year')->default(false);
            $table->enum('year_format', ['YYYY', 'YY'])->default('YYYY'); // 2025 or 25

            // ── Sequence control ─────────────────────────────────────────────────
            // How many digits to pad: 3 → "001", 4 → "0001"
            $table->unsignedTinyInteger('number_padding')->default(4);

            // The number we are AT right now (source of truth for next generation)
            $table->unsignedBigInteger('current_sequence')->default(0);

            // Where the sequence began — used for migration from paper systems
            // e.g. school already has students 1–456, set this to 456 so system starts at 457
            $table->unsignedBigInteger('sequence_start')->default(1);

            // ── Yearly reset ─────────────────────────────────────────────────────
            // true  → sequence resets to sequence_start each new academic year
            //         e.g. secondary schools: 2025/001, 2026/001
            // false → sequence increments forever (lifetime)
            //         e.g. primary schools: 0001, 0002, 0003...
            $table->boolean('reset_yearly')->default(false);

            // Tracks which year the sequence last reset — used to detect year change
            $table->string('last_reset_year')->nullable();

            // ── Manual override ──────────────────────────────────────────────────
            // true  → admin can manually type an admission number (legacy/alpha-numeric schools)
            //         system still validates uniqueness per school
            // false → system always auto-generates
            $table->boolean('allow_manual_override')->default(false);

            // ── Metadata ─────────────────────────────────────────────────────────
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One config per school only
            $table->unique('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_configs');
    }
};