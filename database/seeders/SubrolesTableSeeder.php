<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subrole;

class SubrolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $subroles = [
            ['nombre' => 'OPERARIO', 'guard_name' => 'web'],
            ['nombre' => 'SUPERVISOR', 'guard_name' => 'web'],
            ['nombre' => 'GERENTE', 'guard_name' => 'web'],
            ['nombre' => 'CONTADOR', 'guard_name' => 'web'],
            ['nombre' => 'AUXILIAR ADMINISTRATIVO', 'guard_name' => 'web'],
            ['nombre' => 'JEFE', 'guard_name' => 'web'],
            ['nombre' => 'JACOBO', 'guard_name' => 'web'],
        ];

        foreach ($subroles as $subrole) {
            Subrole::create($subrole);
        }
    }
}