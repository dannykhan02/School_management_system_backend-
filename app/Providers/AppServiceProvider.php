<?php

namespace App\Providers;

use App\Models\Enrollment;
use App\Observers\EnrollmentObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // This one line connects the observer to the Enrollment model.
        // Every time an Enrollment is updated, EnrollmentObserver::updated() fires.
        // It watches for status changing to "approved" and runs the full chain:
        // admission number generation → user creation → student creation.
        Enrollment::observe(EnrollmentObserver::class);
    }
}