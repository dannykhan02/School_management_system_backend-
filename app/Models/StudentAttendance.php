<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendance extends Model  // Make sure class name is StudentAttendance
{
    protected $table = 'attendance_student';
    
    protected $fillable = ['student_id', 'date', 'note', 'recorded_by', 'status'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}