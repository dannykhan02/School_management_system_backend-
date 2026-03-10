<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Get the enrollment being approved so we can scope classroom to correct school
        $enrollment = $this->route('enrollment');
        $schoolId   = $enrollment?->school_id;

        return [
            // ── Final classroom assignment ────────────────────────────────────
            // Required on approval — every student must be placed in a class.
            // Must belong to the same school as the enrollment.
            'assigned_classroom_id' => [
                'required',
                'integer',
                Rule::exists('classrooms', 'id')->where('school_id', $schoolId),
            ],

            // ── Stream assignment ─────────────────────────────────────────────
            // Only required if the school has streams enabled.
            // Validated conditionally based on the school's has_streams flag.
            'assigned_stream_id' => [
                Rule::when(
                    fn() => $enrollment?->school?->has_streams,
                    ['required', 'integer', Rule::exists('streams', 'id')],
                    ['nullable', 'integer', Rule::exists('streams', 'id')]
                ),
            ],

            // ── Optional admin notes on approval ─────────────────────────────
            'admin_notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'assigned_classroom_id.required' => 'You must assign a classroom before approving.',
            'assigned_classroom_id.exists'   => 'The selected classroom does not belong to this school.',
            'assigned_stream_id.required'    => 'You must assign a stream — this school has streams enabled.',
            'assigned_stream_id.exists'      => 'The selected stream does not exist.',
        ];
    }
}