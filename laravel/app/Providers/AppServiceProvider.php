<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keeps unique/indexed string columns under InnoDB's 767-byte key
        // prefix limit on utf8mb4 with older MySQL/MariaDB (e.g. MariaDB
        // 10.1, used on the production host) that lack large_prefix by default.
        Schema::defaultStringLength(191);
    }
}
