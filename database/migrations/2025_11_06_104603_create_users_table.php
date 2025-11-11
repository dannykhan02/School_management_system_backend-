<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
           $table->foreignId('school_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
};