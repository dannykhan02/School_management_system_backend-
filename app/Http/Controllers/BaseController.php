<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class BaseController extends Controller
{
    /**
     * Get the current user (supports Sanctum or manual testing).
     */
    protected function getUser(Request $request)
    {
        $user = Auth::user();

        // Allow fallback for Postman testing without login
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }

        return $user;
    }

    /**
     * Set default password for a user
     */
    protected function setDefaultPassword($user)
    {
        $user->password = Hash::make('password123');
        $user->must_change_password = true;
        $user->save();
    }

    /**
     * Check if user is authorized to access a resource
     */
    protected function checkAuthorization($user, $resource)
    {
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (isset($resource->school_id) && $resource->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This resource does not belong to your school.'], 403);
        }

        return null; // No error, authorized
    }
}