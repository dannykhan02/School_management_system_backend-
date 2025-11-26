<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add 'must_change_password' only if it doesn't exist
            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('status');
            }

            // Add 'last_password_changed_at' only if it doesn't exist
            if (!Schema::hasColumn('users', 'last_password_changed_at')) {
                $table->timestamp('last_password_changed_at')->nullable()->after('must_change_password');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
            if (Schema::hasColumn('users', 'last_password_changed_at')) {
                $table->dropColumn('last_password_changed_at');
            }
        });
    }
};
