<?php

namespace Database\Seeders;

use App\Models\Departamento;
use Illuminate\Database\Seeder;

class DepartamentosTableSeeder extends Seeder
{
    public function run(): void
    {
        $departamentos = [
            ['id'=>0, 'nombre'=>'ADMINISTRADOR', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>1, 'nombre'=>'PESADO ACABADO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>2, 'nombre'=>'PESADO RAMAS', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>3, 'nombre'=>'PESADO PRODUCTO TERMINADO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>4, 'nombre'=>'PESADO HILATURA', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>5, 'nombre'=>'HILATURA', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>6, 'nombre'=>'CONTROL DE CALIDAD', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>7, 'nombre'=>'ACABADO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>8, 'nombre'=>'TEJIDO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>9, 'nombre'=>'TINTORERIA', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>10, 'nombre'=>'ALMACEN DE TELA EN CRUDO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>11, 'nombre'=>'PROGRAMACION Y PLANEACION', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>12, 'nombre'=>'ALMACEN GENERAL', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>13, 'nombre'=>'ACABADO TUBULAR', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>14, 'nombre'=>'ALMACEN TELA ACABADA PT', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>15, 'nombre'=>'SIN ASIGNAR 02', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>16, 'nombre'=>'ESTAMPADO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>17, 'nombre'=>'MANTENIMIENTO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>18, 'nombre'=>'SIN ASIGNAR 03', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>19, 'nombre'=>'PREPARACION', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>20, 'nombre'=>'TEJIDO PLANO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>21, 'nombre'=>'VIGILANCIA', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>22, 'nombre'=>'NINGUNO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>23, 'nombre'=>'LABORATORIO', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>24, 'nombre'=>'OFICINAS', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>25, 'nombre'=>'MANTENIMIENTO Y SERVICIOS', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>26, 'nombre'=>'SIN ASIGNAR 04', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>27, 'nombre'=>'DESARROLLOS', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>28, 'nombre'=>'SIN ASIGNAR 05', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>29, 'nombre'=>'AGENTE DE VENTAS', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>30, 'nombre'=>'VENTAS', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>31, 'nombre'=>'ADMINISTRACION N1', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
            ['id'=>32, 'nombre'=>'ADMINISTRACION N2', 'cuenta_coi'=>null, 'clasificacion'=>null, 'costo'=>null],
        ];

        foreach ($departamentos as $dep) {
            Departamento::updateOrCreate(['id' => $dep['id']], $dep);
        }
    }
}
