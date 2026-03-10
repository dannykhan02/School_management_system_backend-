<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── School and year context ───────────────────────────────────────
            //
            // school_id is intentionally NOT validated here.
            //
            // It must come from the authenticated user ($request->auth_user->school_id)
            // inside the controller — never from the client payload. Accepting it
            // from the request body would allow any admin to forge a different
            // school's ID. Even though the controller overwrites it, having it
            // in validated() is a latent risk if that merge is ever refactored away.
            //
            // academic_year_id must be unique per school so that each school gets
            // exactly one EnrollmentSetting row per term. The Rule::unique check is
            // scoped to the authenticated admin's school and ignores the current
            // record when updating (PUT/PATCH via this same request class).
            'academic_year_id' => [
                'required',
                'integer',
                'exists:academic_years,id',
                Rule::unique('enrollment_settings', 'academic_year_id')
                    ->where('school_id', $this->auth_user?->school_id)
                    ->ignore($this->route('id')), // safe no-op on store (no route param)
            ],

            // ── Enrollment window ─────────────────────────────────────────────
            'enrollment_open' => ['required', 'boolean'],

            // open_date and close_date are optional. When omitted, isAcceptingApplications()
            // falls back to the academic year's start_date / end_date automatically.
            // If both are provided, close_date must be on or after open_date.
            'open_date'  => ['nullable', 'date'],
            'close_date' => ['nullable', 'date', 'after_or_equal:open_date'],

            // ── Capacity ──────────────────────────────────────────────────────
            // 0 = unlimited; any positive integer = hard cap for this term.
            'max_capacity'   => ['required', 'integer', 'min:0'],
            'allow_waitlist' => ['required', 'boolean'],

            // ── Approval behaviour ────────────────────────────────────────────
            'auto_approve'         => ['required', 'boolean'],
            'required_documents'   => ['nullable', 'array'],
            'required_documents.*' => [
                'string',
                Rule::in([
                    'birth_certificate',
                    'passport_photo',
                    'leaving_certificate',
                    'report_card',
                    'immunization_card',
                    'national_id_copy',
                ]),
            ],

            // ── Enrollment types allowed this term ────────────────────────────
            'accept_new_students' => ['required', 'boolean'],
            'accept_transfers'    => ['required', 'boolean'],
            'accept_returning'    => ['required', 'boolean'],

            // ── Notifications ─────────────────────────────────────────────────
            'notify_parent_on_submit'         => ['required', 'boolean'],
            'notify_parent_on_approval'       => ['required', 'boolean'],
            'notify_parent_on_rejection'      => ['required', 'boolean'],
            'notify_admin_on_new_application' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.unique'        => 'Enrollment settings for this term already exist. Use the update endpoint instead.',
            'close_date.after_or_equal'      => 'The close date must be the same as or after the open date.',
            'max_capacity.min'               => 'Capacity cannot be negative. Use 0 for unlimited.',
            'required_documents.*.in'        => 'One or more document types are not recognised.',
        ];
    }

    /**
     * Cross-field validation: at least one enrollment type must be accepted
     * when enrollment is open. Individual field rules cannot express this
     * relationship, so it lives here in withValidator().
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isOpen     = $this->boolean('enrollment_open');
            $acceptsAny = $this->boolean('accept_new_students')
                       || $this->boolean('accept_transfers')
                       || $this->boolean('accept_returning');

            if ($isOpen && ! $acceptsAny) {
                $validator->errors()->add(
                    'accept_new_students',
                    'At least one enrollment type must be accepted when enrollment is open.'
                );
            }
        });
    }
}