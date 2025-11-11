<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('year');
            $table->string('term');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            
            $table->unique(['school_id', 'year', 'term']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('academic_years');
    }
};