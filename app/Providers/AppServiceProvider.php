<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Auth::provider('silestari', fn (Application $app, array $config): LegacyUserProvider => new LegacyUserProvider($app['hash'], $config['model']));
        Gate::define('manage-users', fn (User $user): bool => $user->isAdmin());
        Gate::define('access-module', fn (User $user, string $module): bool => $user->hasModule($module));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()));
        RateLimiter::for('setup', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
