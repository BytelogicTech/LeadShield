<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (! app()->environment('testing')) {
            $root = rtrim((string) config('app.url'), '/');
            if ($root !== '') {
                URL::forceRootUrl($root);
            }
        }

        $lifetime = (int) config('leadshield.session_lifetime_minutes', 525600);
        config(['session.lifetime' => $lifetime]);
    }
}
