<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdmissionConfig;
use App\Http\Requests\StoreAdmissionConfigRequest;
use App\Services\AdmissionNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmissionConfigController extends Controller
{
    public function __construct(
        protected AdmissionNumberService $admissionNumberService
    ) {}

    /**
     * Get the current admission number config for the admin's school.
     *
     * GET /api/admin/admission-config
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $config = AdmissionConfig::where('school_id', $user->school_id)->first();

        if (! $config) {
            return response()->json([
                'status'  => 'success',
                'config'  => null,
                'preview' => null,
                'message' => 'No admission number configuration found. Please set one up.',
            ]);
        }

        return response()->json([
            'status'  => 'success',
            'config'  => $config,
            'preview' => $config->previewNextNumber(),
        ]);
    }

    /**
     * Save (create or update) the admission number configuration.
     *
     * POST /api/admin/admission-config
     */
    public function store(StoreAdmissionConfigRequest $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $config = AdmissionConfig::updateOrCreate(
            ['school_id' => $user->school_id],
            array_merge($request->validated(), [
                'school_id'     => $user->school_id,
                'configured_by' => $user->id,
            ])
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Admission number configuration saved.',
            'config'  => $config->fresh(),
            'preview' => $config->fresh()->previewNextNumber(),
        ]);
    }

    /**
     * Live preview — returns what the next number will look like WITHOUT
     * saving anything or touching the sequence counter.
     * Used by the admin UI as the admin types their config.
     *
     * GET /api/admin/admission-config/preview?pattern={PREFIX}/{YEAR}/{NUMBER}&prefix=KHS&...
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'pattern'        => ['required', 'string'],
            'prefix'         => ['nullable', 'string', 'max:20'],
            'separator'      => ['nullable', 'string'],
            'number_padding' => ['required', 'integer', 'min:1', 'max:6'],
            'include_year'   => ['required', 'boolean'],
            'year_format'    => ['nullable', 'string'],
            'sequence_start' => ['nullable', 'integer', 'min:1'],
        ]);

        // Build a temporary in-memory config — nothing is saved to DB
        $tempConfig = new AdmissionConfig([
            'enabled'          => true,
            'pattern'          => $request->input('pattern'),
            'prefix'           => $request->input('prefix'),
            'separator'        => $request->input('separator', '/'),
            'include_year'     => $request->boolean('include_year'),
            'year_format'      => $request->input('year_format', 'YYYY'),
            'number_padding'   => $request->input('number_padding', 4),
            'current_sequence' => ($request->input('sequence_start', 1)) - 1,
        ]);

        $preview = $tempConfig->previewNextNumber();

        return response()->json([
            'status'  => 'success',
            'preview' => $preview,
            'example' => "The first admission number will be: {$preview}",
        ]);
    }

    /**
     * Reset the sequence to a specific number.
     * Used when migrating from a paper system.
     * e.g. school already has 456 students on paper → set to 456
     * so the next generated number is 457.
     *
     * POST /api/admin/admission-config/reset-sequence
     */
    public function resetSequence(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'current_sequence' => ['required', 'integer', 'min:0'],
        ]);

        $config = AdmissionConfig::where('school_id', $user->school_id)->firstOrFail();

        $this->admissionNumberService->resetSequence(
            $user->school_id,
            $request->input('current_sequence')
        );

        $fresh = $config->fresh();

        return response()->json([
            'status'       => 'success',
            'message'      => 'Sequence reset. Next generated number will start from ' .
                              ($request->input('current_sequence') + 1) . '.',
            'config'       => $fresh,
            'next_preview' => $fresh->previewNextNumber(),
        ]);
    }
}