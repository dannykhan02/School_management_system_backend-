<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnrollmentRequest extends FormRequest
{
    /**
     * Authorization is handled by EnrollmentPolicy.
     * Only admins of the same school can update enrollments.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Admin notes ───────────────────────────────────────────────────
            // Internal notes — never shown to the parent
            'admin_notes' => ['nullable', 'string'],

            // ── Final classroom/stream assignment ─────────────────────────────
            // Admin sets these during review, before or during approval.
            // They override what the applicant requested.
            'assigned_classroom_id' => [
                'nullable',
                'integer',
                Rule::exists('classrooms', 'id'),
            ],
            'assigned_stream_id' => [
                'nullable',
                'integer',
                Rule::exists('streams', 'id'),
            ],

            // ── Document upload updates ───────────────────────────────────────
            // Admin can request additional documents and applicant re-submits them
            'documents'   => ['nullable', 'array'],
            'documents.*' => ['nullable', 'string'],
        ];
    }
}