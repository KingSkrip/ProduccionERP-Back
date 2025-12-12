<?php

namespace Database\Seeders;

use App\Models\Departamento;
use Illuminate\Database\Seeder;

class DepartamentosTableSeeder extends Seeder
{
    public function run(): void
    {
        $departamentos = [
            ['id'=>0, 'nombre'=>'ADMINISTRADOR'],
            ['id'=>1, 'nombre'=>'PESADO ACABADO'],
            ['id'=>2, 'nombre'=>'PESADO RAMAS'],
            ['id'=>3, 'nombre'=>'PESADO PRODUCTO TERMINADO'],
            ['id'=>4, 'nombre'=>'PESADO HILATURA'],
            ['id'=>5, 'nombre'=>'HILATURA'],
            ['id'=>6, 'nombre'=>'CONTROL DE CALIDAD'],
            ['id'=>7, 'nombre'=>'ACABADO'],
            ['id'=>8, 'nombre'=>'TEJIDO'],
            ['id'=>9, 'nombre'=>'TINTORERIA'],
            ['id'=>10, 'nombre'=>'ALMACEN DE TELA EN CRUDO'],
            ['id'=>11, 'nombre'=>'PROGRAMACION Y PLANEACION'],
            ['id'=>12, 'nombre'=>'ALMACEN GENERAL'],
            ['id'=>13, 'nombre'=>'ACABADO TUBULAR'],
            ['id'=>14, 'nombre'=>'ALMACEN TELA ACABADA PT'],
            ['id'=>15, 'nombre'=>'SIN ASIGNAR 02'],
            ['id'=>16, 'nombre'=>'ESTAMPADO'],
            ['id'=>17, 'nombre'=>'MANTENIMIENTO'],
            ['id'=>18, 'nombre'=>'SIN ASIGNAR 03'],
            ['id'=>19, 'nombre'=>'PREPARACION'],
            ['id'=>20, 'nombre'=>'TEJIDO PLANO'],
            ['id'=>21, 'nombre'=>'VIGILANCIA'],
            ['id'=>22, 'nombre'=>'NINGUNO'],
            ['id'=>23, 'nombre'=>'LABORATORIO'],
            ['id'=>24, 'nombre'=>'OFICINAS'],
            ['id'=>25, 'nombre'=>'MANTENIMIENTO Y SERVICIOS'],
            ['id'=>26, 'nombre'=>'SIN ASIGNAR 04'],
            ['id'=>27, 'nombre'=>'DESARROLLOS'],
            ['id'=>28, 'nombre'=>'SIN ASIGNAR 05'],
            ['id'=>29, 'nombre'=>'AGENTE DE VENTAS'],
            ['id'=>30, 'nombre'=>'VENTAS'],
            ['id'=>31, 'nombre'=>'ADMINISTRACION N1'],
            ['id'=>32, 'nombre'=>'ADMINISTRACION N2'],
        ];

        foreach ($departamentos as $dep) {
            Departamento::updateOrCreate(['id' => $dep['id']], $dep);
        }
    }
}
