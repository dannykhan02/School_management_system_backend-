<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    /**
     * Handle login and return token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            Log::warning("FAILED LOGIN ATTEMPT", [
                'email' => $request->email,
                'ip'    => $request->ip()
            ]);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();

        // Create token
        $token = $user->createToken('postman-login')->plainTextToken;

        // LOG THE SUCCESSFUL LOGIN
        Log::info("USER LOGGED IN", [
            'user_id'  => $user->id,
            'email'    => $user->email,
            'name'     => $user->name ?? null,
            'role'     => $user->role ?? 'undefined',
            'token'    => $token,
            'ip'       => $request->ip(),
            'userAgent'=> $request->header('User-Agent'),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'user'    => [
                'id'    => $user->id,
                'email' => $user->email,
                'name'  => $user->name ?? null,
            ],
            'token' => $token,
        ], 200);
    }
}
