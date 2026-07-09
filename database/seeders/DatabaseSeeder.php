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
            ModelHasRoleSeeder::class,
            PrioritySeeder::class,
            CitasTypesSeeder::class,
            AreaSeeder::class,
            PuestoSeeder::class,
            ChecadorCatalogoPermisoSeeder::class,
            ChecadorCatalogoMovimientoSeeder::class,
            // DepartamentosTableSeeder::class,
            // TiposFaltasSeeder::class,
            // TipoAfectacionesSeeder::class,
            // Agrega aquí todos los seeders que vayas creando
        ]);
    }
}