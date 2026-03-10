<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Master toggle ─────────────────────────────────────────────────
            'enabled' => ['required', 'boolean'],

            // ── Pattern ───────────────────────────────────────────────────────
            // Must contain {NUMBER} — this is the only required token.
            // Valid examples:
            //   "{NUMBER}"                 → 0001
            //   "{YEAR}/{NUMBER}"          → 2025/001
            //   "{PREFIX}/{YEAR}/{NUMBER}" → KHS/2025/001
            'pattern' => [
                'required_if:enabled,true',
                'string',
                'max:100',
                function ($attribute, $value, $fail) {
                    if (! str_contains($value, '{NUMBER}')) {
                        $fail('The pattern must contain the {NUMBER} token.');
                    }
                    // Ensure pattern only uses valid tokens
                    $cleaned = str_replace(
                        ['{PREFIX}', '{YEAR}', '{NUMBER}', '{SEP}'],
                        ['', '', '', ''],
                        $value
                    );
                    // After removing valid tokens, only separators and static chars should remain
                    if (preg_match('/\{[^}]+\}/', $cleaned)) {
                        $fail('The pattern contains an invalid token. Only {PREFIX}, {YEAR}, {NUMBER}, and {SEP} are allowed.');
                    }
                },
            ],

            // ── Prefix ────────────────────────────────────────────────────────
            // Only letters, numbers, and hyphens. e.g. "KHS", "ADM", "GHS-2"
            'prefix' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9\-]+$/',
            ],

            // ── Separator ─────────────────────────────────────────────────────
            // Only allowed separators: /, -, _, . or empty string
            'separator' => [
                'required_if:enabled,true',
                'string',
                Rule::in(['/', '-', '_', '.', '']),
            ],

            // ── Year settings ─────────────────────────────────────────────────
            'include_year' => ['required', 'boolean'],
            'year_format'  => [
                'required_if:include_year,true',
                Rule::in(['YYYY', 'YY']),
            ],

            // ── Sequence settings ─────────────────────────────────────────────
            // Padding: how many digits — min 1 (e.g. "1"), max 6 (e.g. "000001")
            'number_padding'  => ['required_if:enabled,true', 'integer', 'min:1', 'max:6'],

            // sequence_start: where to begin generating from
            // Useful for schools migrating from paper: set to last used number
            'sequence_start'  => ['required_if:enabled,true', 'integer', 'min:1'],

            // current_sequence: the actual counter — can only be set upward, never back
            // Admin uses this to fast-forward if migrating existing students
            'current_sequence' => [
                'sometimes',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    // Prevent rewinding the sequence — would cause duplicate numbers
                    $schoolId = $this->input('school_id') ?? $this->route('school')?->id;
                    if ($schoolId) {
                        $existing = \App\Models\AdmissionConfig::where('school_id', $schoolId)
                            ->value('current_sequence');

                        if ($existing !== null && $value < $existing) {
                            $fail(
                                "You cannot set the sequence below its current value ({$existing}). " .
                                "This would cause duplicate admission numbers."
                            );
                        }
                    }
                },
            ],

            // ── Yearly reset ──────────────────────────────────────────────────
            // true  = sequence resets each academic year (secondary schools)
            // false = sequence increments forever (primary schools)
            'reset_yearly' => ['required', 'boolean'],

            // ── Manual override ───────────────────────────────────────────────
            'allow_manual_override' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'pattern.required_if'          => 'A pattern is required when admission numbers are enabled.',
            'prefix.regex'                  => 'Prefix may only contain letters, numbers, and hyphens.',
            'separator.in'                  => 'Separator must be one of: / - _ . or empty.',
            'number_padding.min'            => 'Number padding must be at least 1 digit.',
            'number_padding.max'            => 'Number padding cannot exceed 6 digits.',
            'sequence_start.min'            => 'Sequence start must be at least 1.',
            'year_format.required_if'       => 'Year format is required when include year is enabled.',
            'year_format.in'                => 'Year format must be YYYY (e.g. 2025) or YY (e.g. 25).',
        ];
    }

    /**
     * Add extra data needed for validation that isn't in the request body.
     * Specifically the school_id from the authenticated user's context.
     */
    protected function prepareForValidation(): void
    {
        // If school_id is not in the request body, pull it from the route
        if (! $this->has('school_id')) {
            $schoolId = $this->route('school')?->id
                ?? auth()->user()?->school_id;

            if ($schoolId) {
                $this->merge(['school_id' => $schoolId]);
            }
        }
    }
}