<?php

namespace Database\Seeders;

use App\Models\ChecadorCatalogoMovimiento;
use Illuminate\Database\Seeder;

class ChecadorCatalogoMovimientoSeeder extends Seeder
{
    public function run(): void
    {
        $movs = [
            ['nombre' => 'Entrada', 'clave' => 'ENT', 'codigo_legado' => '1', 'cuenta_como_entrada' => true, 'orden' => 1],
            ['nombre' => 'Salida a comer', 'clave' => 'SAL_COM', 'codigo_legado' => '2', 'cuenta_como_salida' => true, 'orden' => 2],
            ['nombre' => 'Regreso de comer', 'clave' => 'REG_COM', 'codigo_legado' => '3', 'cuenta_como_entrada' => true, 'orden' => 3],
            ['nombre' => 'Salida por función', 'clave' => 'SAL_FUN', 'codigo_legado' => '4', 'cuenta_como_salida' => true, 'orden' => 4],
            ['nombre' => 'Entrada por función', 'clave' => 'ENT_FUN', 'codigo_legado' => '5', 'cuenta_como_entrada' => true, 'orden' => 5],
            ['nombre' => 'Salida', 'clave' => 'SAL', 'codigo_legado' => '6', 'cuenta_como_salida' => true, 'orden' => 6],
        ];

        foreach ($movs as $mov) {
            ChecadorCatalogoMovimiento::updateOrCreate(['clave' => $mov['clave']], $mov);
        }
    }
}