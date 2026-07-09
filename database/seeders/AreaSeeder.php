<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [

            [
                'nombre' => 'Dirección General',
                'descripcion' => 'Dirección General',
            ],
            [
                'nombre' => 'Seguridad',
                'descripcion' => 'Área de Seguridad',
            ],
            [
                'nombre' => 'Recursos Humanos',
                'descripcion' => 'Área de Recursos Humanos',
            ],
            [
                'nombre' => 'Recepción de Materiales',
                'descripcion' => 'Recepción de Materiales',
            ],
            [
                'nombre' => 'Sistemas',
                'descripcion' => 'Área de Sistemas',
            ],
            [
                'nombre' => 'Tejido',
                'descripcion' => 'Área de Tejido',
            ],
            [
                'nombre' => 'Producción',
                'descripcion' => 'Área de Producción',
            ],
            [
                'nombre' => 'Mantenimiento',
                'descripcion' => 'Área de Mantenimiento',
            ],
            [
                'nombre' => 'Colorimetría',
                'descripcion' => 'Laboratorio de Colorimetría',
            ],
            [
                'nombre' => 'Tintorería',
                'descripcion' => 'Área de Tintorería',
            ],
            [
                'nombre' => 'Acabado',
                'descripcion' => 'Área de Acabado',
            ],
            [
                'nombre' => 'Estampado',
                'descripcion' => 'Área de Estampado',
            ],
            [
                'nombre' => 'Almacén de Crudo',
                'descripcion' => 'Almacén de Crudo',
            ],
            [
                'nombre' => 'Embarque',
                'descripcion' => 'Área de Embarque',
            ],

        ];

        foreach ($areas as $area) {
            Area::updateOrCreate(
                ['nombre' => $area['nombre']],
                [
                    'descripcion' => $area['descripcion'],
                    'activo' => true,
                ]
            );
        }
    }
}