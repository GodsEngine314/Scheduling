<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            StoreSeeder::class,
            TestUserSeeder::class,
        ]);

        // NOT in the list above: it is the only seeder here that makes network
        // calls, so a run without TCP credentials — CI, a fresh clone — would
        // fail the whole seed over data the schema does not need. Run it on
        // purpose: `php artisan db:seed --class=EmployeeSeeder`.
    }
}
