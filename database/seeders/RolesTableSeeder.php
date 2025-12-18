<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nombre' => 'COLABORADOR', 'guard_name' => 'web'],
            ['nombre' => 'RH', 'guard_name' => 'web'],
            ['nombre' => 'SUADMIN', 'guard_name' => 'web'],
            ['nombre' => 'ADMIN', 'guard_name' => 'web'],
            ['nombre' => 'JEFE', 'guard_name' => 'web'],
        ];

        foreach ($roles as $role) {
            Rol::create($role);
        }
    }
}
