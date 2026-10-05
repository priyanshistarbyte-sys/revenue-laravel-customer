<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Amaira seeds its defaults (settings + currencies) from within the
     * schema migration. The first admin user is created via the PIN login
     * "setup" screen, so there is nothing to seed here.
     */
    public function run(): void
    {
        //
    }
}
