<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API login endpoint - returns JSON for unauthenticated requests
Route::get('/login', function () {
    return response()->json([
        'message' => 'Unauthenticated. Please log in via the API.'
    ], 401);
})->name('login');