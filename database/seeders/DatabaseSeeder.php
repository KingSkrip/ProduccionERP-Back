<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StatusesTableSeeder::class,
            RolesTableSeeder::class,
            SubrolesTableSeeder::class,
            TurnosSeeder::class,
            // DepartamentosTableSeeder::class,
            // TiposFaltasSeeder::class,
            // TipoAfectacionesSeeder::class,
            // Agrega aquí todos los seeders que vayas creando
        ]);
    }
}
