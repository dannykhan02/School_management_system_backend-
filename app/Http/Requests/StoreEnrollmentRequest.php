<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\EnrollmentSetting;

class StoreEnrollmentRequest extends FormRequest
{
    /**
     * Anyone can submit an enrollment application.
     * School-level open/close validation is handled in EnrollmentService::submit()
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isTransfer           = $this->input('enrollment_type') === 'transfer' || $this->input('is_transfer') == true;
        $isGovernmentPlacement = $this->input('enrollment_type') === 'government_placement';

        return [
            // ── Required context ──────────────────────────────────────────────
            'school_id'        => ['required', 'integer', 'exists:schools,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'enrollment_type'  => ['required', Rule::in(['new', 'transfer', 'returning', 'government_placement'])],

            // ── Student personal information ──────────────────────────────────
            'first_name'               => ['required', 'string', 'max:255'],
            'last_name'                => ['required', 'string', 'max:255'],
            'middle_name'              => ['nullable', 'string', 'max:255'],
            'date_of_birth'            => ['required', 'date', 'before:today'],
            'gender'                   => ['required', Rule::in(['male', 'female', 'other'])],
            'nationality'              => ['nullable', 'string', 'max:255'],
            'religion'                 => ['nullable', 'string', 'max:255'],
            'birth_certificate_number' => ['nullable', 'string', 'max:255'],

            // Special needs — details only required if special_needs is true
            'special_needs'         => ['required', 'boolean'],
            'special_needs_details' => ['nullable', 'required_if:special_needs,true', 'string'],

            // ── Parent / Guardian information ─────────────────────────────────
            'parent_first_name'   => ['required', 'string', 'max:255'],
            'parent_last_name'    => ['required', 'string', 'max:255'],
            'parent_phone'        => ['required', 'string', 'max:20'],
            'parent_email'        => ['nullable', 'email', 'max:255'],
            'parent_national_id'  => ['nullable', 'string', 'max:50'],
            'parent_relationship' => ['required', Rule::in(['father', 'mother', 'guardian', 'other'])],
            'parent_occupation'   => ['nullable', 'string', 'max:255'],
            'parent_address'      => ['nullable', 'string'],

            // ── Class / Stream preference ─────────────────────────────────────
            // Applicant can request a class — admin makes final assignment on approval
            'applying_for_classroom_id' => [
                'nullable',
                'integer',
                // Must belong to the same school they are applying to
                Rule::exists('classrooms', 'id')->where('school_id', $this->input('school_id')),
            ],
            'applying_for_stream_id' => [
                'nullable',
                'integer',
                Rule::exists('streams', 'id'),
            ],

            // ── Transfer-specific fields ──────────────────────────────────────
            // These become required when enrollment_type = transfer OR is_transfer = true
            'is_transfer'                => ['required', 'boolean'],
            'previous_school_name'       => [
                'nullable',
                Rule::requiredIf($isTransfer),
                'string', 'max:255',
            ],
            'previous_school_address'    => ['nullable', 'string', 'max:255'],
            'previous_admission_number'  => ['nullable', 'string', 'max:100'],
            'leaving_certificate_number' => ['nullable', 'string', 'max:100'],
            'last_class_attended'        => [
                'nullable',
                Rule::requiredIf($isTransfer),
                'string', 'max:100',
            ],

            // ── Government placement fields ───────────────────────────────────
            // Required when enrollment_type = government_placement
            'assessment_index_number'  => [
                'nullable',
                Rule::requiredIf($isGovernmentPlacement),
                'string',
                'max:30',
            ],
            'placement_year'           => [
                'nullable',
                Rule::requiredIf($isGovernmentPlacement),
                'integer',
                'min:2000',
                'max:' . (date('Y') + 1),
            ],
            'placement_reference_code' => [
                'nullable',
                Rule::requiredIf($isGovernmentPlacement),
                'string',
                'max:50',
            ],
            'placement_school_name'    => [
                'nullable',
                Rule::requiredIf($isGovernmentPlacement),
                'string',
                'max:200',
            ],

            // ── Documents ─────────────────────────────────────────────────────
            // JSON object of uploaded file paths keyed by document slug
            // e.g. { "birth_certificate": "documents/xyz.pdf", "passport_photo": "documents/abc.jpg" }
            'documents'   => ['nullable', 'array'],
            'documents.*' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'school_id.exists'                   => 'The selected school does not exist.',
            'academic_year_id.exists'            => 'The selected academic year does not exist.',
            'date_of_birth.before'               => 'Date of birth must be in the past.',
            'special_needs_details.required_if'  => 'Please describe the special needs when special needs is indicated.',
            'applying_for_classroom_id.exists'   => 'The selected classroom does not belong to this school.',
            'previous_school_name.required_if'   => 'Previous school name is required for transfer applications.',
            'last_class_attended.required_if'    => 'Last class attended is required for transfer applications.',
            'assessment_index_number.required_if' => 'Assessment index number is required for government placement applications.',
            'placement_year.required_if'          => 'Placement year is required for government placement applications.',
            'placement_reference_code.required_if'=> 'Placement reference code is required for government placement applications.',
            'placement_school_name.required_if'   => 'Placement school name is required for government placement applications.',
        ];
    }

    /**
     * Prepare the data for validation.
     * Syncs is_transfer with enrollment_type.
     * Ensures government_placement applications start with pending verification status.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('enrollment_type') === 'transfer') {
            $this->merge(['is_transfer' => true]);
        }

        // Government placements always start at pending — never trust client input
        if ($this->input('enrollment_type') === 'government_placement') {
            $this->merge(['placement_verification_status' => 'pending']);
        }
    }
}