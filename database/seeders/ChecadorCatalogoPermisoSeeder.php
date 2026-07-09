<?php

namespace Database\Seeders;

use App\Models\ChecadorCatalogoPermiso;
use Illuminate\Database\Seeder;

class ChecadorCatalogoPermisoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [
                'nombre' => 'Hora de comida',
                'clave' => 'COMIDA',
                'codigo_legado' => null, // no venía como Incidencia en el viejo, era columna aparte (SySMovsStat.SalirAComer)
                'descripcion' => 'Salida para tomar alimentos dentro de la jornada.',
                'duracion_default_minutos' => 60,
                'tipo_unidad' => 'horas',
                'requiere_regreso_mismo_dia' => '1',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => false,
                'orden' => 1,
            ],
            [
                'nombre' => 'Cita médica',
                'clave' => 'MEDICO',
                'codigo_legado' => '01',
                'descripcion' => 'Permiso para acudir a consulta o cita médica.',
                'duracion_default_minutos' => 120,
                'tipo_unidad' => 'horas',
                'requiere_regreso_mismo_dia' => '0',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => true,
                'orden' => 2,
            ],
            [
                'nombre' => 'Trámite personal',
                'clave' => 'TRAMITE',
                'codigo_legado' => '02',
                'descripcion' => 'Trámites personales, bancarios o gubernamentales.',
                'duracion_default_minutos' => 90,
                'tipo_unidad' => 'horas',
                'requiere_regreso_mismo_dia' => '0',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => true,
                'orden' => 3,
            ],
            [
                'nombre' => 'Incapacidad',
                'clave' => 'INCAPACIDAD',
                'codigo_legado' => '03', // ver tabla Incapacidades del viejo sistema (CodIncap/NomIncap)
                'descripcion' => 'Incapacidad médica avalada por IMSS/ISSSTE.',
                'duracion_default_minutos' => null, // se mide en días, no en minutos
                'tipo_unidad' => 'dias',
                'requiere_regreso_mismo_dia' => '0',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => true,
                'orden' => 4,
            ],
           [
                'nombre' => 'Personal',
                'clave' => 'PERSONAL',
                'codigo_legado' => '05',
                'descripcion' => 'Permiso autorizado que NO cuenta como tiempo trabajado.',
                'duracion_default_minutos' => null,
                'tipo_unidad' => 'dias',
                'requiere_regreso_mismo_dia' => '0',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => true,
                'orden' => 5,
            ],
            [
                'nombre' => 'Permiso sin goce de sueldo',
                'clave' => 'SIN_GOCE_SUELDO',
                'codigo_legado' => '05',
                'descripcion' => 'Permiso autorizado que NO cuenta como tiempo trabajado.',
                'duracion_default_minutos' => null,
                'tipo_unidad' => 'dias',
                'requiere_regreso_mismo_dia' => '0',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => true,
                'orden' => 6,
            ],
            [
                'nombre' => 'Salida por función/trabajo',
                'clave' => 'FUNCION',
                'codigo_legado' => null, // en el viejo era SalidaEnFuncion/EntradaEnFuncion, no un Incidencia
                'descripcion' => 'Salida a comisión, entrega, visita a cliente, etc. Sí cuenta como jornada.',
                'duracion_default_minutos' => null,
                'tipo_unidad' => 'horas',
                'requiere_regreso_mismo_dia' => '1',
                'suma_a_horas_trabajadas' => true,
                'requiere_aprobacion' => false,
                'orden' => 7,
            ],
            [
                'nombre' => 'Permiso extraordinario',
                'clave' => 'EXTRA',
                'codigo_legado' => null,
                'descripcion' => 'Caso especial no contemplado en los tipos anteriores.',
                'duracion_default_minutos' => null,
                'tipo_unidad' => 'horas',
                'requiere_regreso_mismo_dia' => '0',
                'suma_a_horas_trabajadas' => false,
                'requiere_aprobacion' => true,
                'orden' => 8,
            ],
        ];

        foreach ($tipos as $tipo) {
            ChecadorCatalogoPermiso::updateOrCreate(['clave' => $tipo['clave']], $tipo);
        }
    }
}