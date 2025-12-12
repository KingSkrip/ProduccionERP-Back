<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoAfectacionesSeeder extends Seeder
{
    public function run(): void
    {
        $afectaciones = [
            ['nombre' => 'Afecta Septimo Día', 'descripcion' => 'Indica si afecta el séptimo día de descanso'],
            ['nombre' => 'Tipo de Incapacidad', 'descripcion' => 'Clasificación de incapacidad'],
            ['nombre' => 'Afecta Dias IMSS', 'descripcion' => 'Si afecta días IMSS para cálculo'],
            ['nombre' => 'Afecta Dias PTU', 'descripcion' => 'Si afecta días para cálculo de PTU'],
            ['nombre' => 'Afecta Info Interna', 'descripcion' => 'Si afecta información interna'],
            ['nombre' => 'Mostrar en Consultas', 'descripcion' => 'Indica si se muestra en consultas'],
        ];

        DB::table('tipo_afectaciones')->insert($afectaciones);
    }
}
