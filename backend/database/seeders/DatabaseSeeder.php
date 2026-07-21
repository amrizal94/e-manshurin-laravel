<?php

namespace Database\Seeders;

use App\Models\Daerah;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        Daerah::firstOrCreate(['nama' => 'Kediri Selatan 1']);

        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@e-manshurin.test'],
            ['name' => 'Super Admin', 'password' => 'password']
        );
        $superAdmin->syncRoles(['super_admin']);
    }
}
