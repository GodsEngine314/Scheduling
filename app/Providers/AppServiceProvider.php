<?php

namespace App\Providers;

use App\Support\ActingUser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound, but stateless: ActingUser reads the current request on every
        // call rather than capturing one, so it cannot carry an actor from one
        // request into the next.
        $this->app->bind(ActingUser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
