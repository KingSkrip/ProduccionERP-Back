<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

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
            ['nombre' => 'CLIENTE', 'guard_name' => 'web'],
            ['nombre' => 'AGENTE', 'guard_name' => 'web'],
            ['nombre' => 'PROVEDORES', 'guard_name' => 'web'],
            ['nombre' => 'REGISTRO_ACCESOS', 'guard_name' => 'web'],
            ['nombre' => 'GUARDIA', 'guard_name' => 'web'],
        ];

        foreach ($roles as $role) {
            Rol::create($role);
        }
    }
}
