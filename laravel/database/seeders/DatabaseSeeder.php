<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Local development bootstraps its first admin through the "Open local
     * preview" button on /login (see AuthController::local). For a real
     * deployment, create the first administrator with:
     *   php artisan erp:make-admin you@company.com "Your Name"
     */
    public function run(): void
    {
        //
    }
}
