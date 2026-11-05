<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Le jeu de démonstration n'est jamais injecté en production sans accord explicite (LIV-03).
     */
    public function run(): void
    {
        $this->call(ReferenceSeeder::class);

        if (app()->isProduction() && ! env('SAIE_ALLOW_DEMO_SEED')) {
            $this->command?->warn('Production : jeu de démonstration ignoré (SAIE_ALLOW_DEMO_SEED absent).');

            return;
        }
        if (config('saie.demo_mode') || app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
