<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redis;

Route::get('/', function () {
    return view('welcome');
});

// API login endpoint - returns JSON for unauthenticated requests
Route::get('/login', function () {
    return response()->json([
        'message' => 'Unauthenticated. Please log in via the API.'
    ], 401);
})->name('login');

Route::get('/test-redis', function () {
    try {
        Redis::set('school_test', 'Redis is working! 🚀');
        $value = Redis::get('school_test');
        
        return response()->json([
            'status' => 'SUCCESS ✅',
            'message' => 'Redis connected to Laravel!',
            'test_value' => $value,
            'timestamp' => now()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'ERROR ❌',
            'message' => $e->getMessage()
        ], 500);
    }
});