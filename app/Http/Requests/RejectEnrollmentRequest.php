<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Rejection reason is mandatory — parent must be told why
            'rejection_reason' => ['required', 'string', 'min:10', 'max:1000'],

            // Optional internal notes separate from what parent sees
            'admin_notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A rejection reason is required. The parent will be informed.',
            'rejection_reason.min'      => 'Please provide a meaningful rejection reason (at least 10 characters).',
        ];
    }
}