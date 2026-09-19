<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Dati di sviluppo locale. In produzione usare `php artisan family:create`.
     */
    public function run(): void
    {
        $family = Family::create([
            'name' => 'Giopellino',
            'slug' => 'giopellino',
            'app_url' => 'http://localhost:5173',
        ]);

        User::factory()->admin()->for($family)->create([
            'name' => 'Admin Giopellino',
            'email' => 'admin@giopellino.test',
        ]);
    }
}
