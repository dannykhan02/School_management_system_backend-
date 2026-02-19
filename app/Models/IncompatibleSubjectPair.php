<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncompatibleSubjectPair extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_selection_rule_id',
        'subject_id',
        'incompatible_with_subject_id'
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SubjectSelectionRule::class, 'subject_selection_rule_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function incompatibleSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'incompatible_with_subject_id');
    }
}