<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('class_name');
            $table->foreignId('class_teacher_id')->nullable()->constrained('teachers')->onDelete('set null');
            $table->integer('capacity')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('classes');
    }
};