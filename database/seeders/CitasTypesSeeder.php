<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitasTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('citas_types')->insert([
            [
                'nombre' => 'Visita proveedor',
                'slug' => 'visita_proveedor',
                'descripcion' => 'Citas con proveedores externos',
                'color' => '#0ea5e9',
                'icono' => 'truck',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Junta interna',
                'slug' => 'junta_interna',
                'descripcion' => 'Reuniones entre colaboradores y jefes',
                'color' => '#22c55e',
                'icono' => 'users',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Seguimiento',
                'slug' => 'seguimiento',
                'descripcion' => 'Seguimiento de actividades',
                'color' => '#f59e0b',
                'icono' => 'clipboard',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Capacitación',
                'slug' => 'capacitacion',
                'descripcion' => 'Capacitaciones internas',
                'color' => '#a855f7',
                'icono' => 'graduation-cap',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
