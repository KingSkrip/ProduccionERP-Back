<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TurnosSeeder extends Seeder
{
    public function run(): void
    {
        $empresas = ['01', '02', '03', '04'];

        $turnos = [
            [
                'clave' => 'MAT',
                'nombre' => 'Matutino',
                'hora_entrada' => '06:00',
                'hora_salida' => '14:00',
                'hora_inicio_comida' => '10:00',
                'hora_fin_comida' => '10:30',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5], // Lun-Vie
                'dias_descanso' => [0, 6], // Dom-Sáb
            ],
            [
                'clave' => 'VES',
                'nombre' => 'Vespertino',
                'hora_entrada' => '14:00',
                'hora_salida' => '22:00',
                'hora_inicio_comida' => '18:00',
                'hora_fin_comida' => '18:30',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5],
                'dias_descanso' => [0, 6],
            ],
            [
                'clave' => 'NOC',
                'nombre' => 'Nocturno',
                'hora_entrada' => '22:00',
                'hora_salida' => '06:00',
                'hora_inicio_comida' => '02:00',
                'hora_fin_comida' => '02:30',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => true,
                'dias_laborables' => [1, 2, 3, 4, 5],
                'dias_descanso' => [0, 6],
            ],
            [
                'clave' => 'MIX',
                'nombre' => 'Mixto',
                'hora_entrada' => null,
                'hora_salida' => null,
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5],
                'dias_descanso' => [0, 6],
            ],
            [
                'clave' => 'COMP',
                'nombre' => 'Jornada completa',
                'hora_entrada' => '08:00',
                'hora_salida' => '17:00',
                'hora_inicio_comida' => '13:00',
                'hora_fin_comida' => '14:00',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5],
                'dias_descanso' => [0, 6],
            ],
            [
                'clave' => 'MED',
                'nombre' => 'Medio turno',
                'hora_entrada' => null,
                'hora_salida' => null,
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5, 6],
                'dias_descanso' => [0], // Solo domingo
            ],
            [
                'clave' => '12X12',
                'nombre' => '12x12',
                'hora_entrada' => '08:00',
                'hora_salida' => '20:00',
                'hora_inicio_comida' => '14:00',
                'hora_fin_comida' => '15:00',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 3, 5, 0], // Lun, Mié, Vie, Dom
                'dias_descanso' => [2, 4, 6], // Mar, Jue, Sáb
            ],
            [
                'clave' => '24X24',
                'nombre' => '24x24',
                'hora_entrada' => '08:00',
                'hora_salida' => '08:00',
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => true,
                'dias_laborables' => [1, 3, 5, 0],
                'dias_descanso' => [2, 4, 6],
            ],
            [
                'clave' => '24X48',
                'nombre' => '24x48',
                'hora_entrada' => '08:00',
                'hora_salida' => '08:00',
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => true,
                'dias_laborables' => [1, 4, 0], // Lun, Jue, Dom
                'dias_descanso' => [2, 3, 5, 6],
            ],
            [
                'clave' => '3X8',
                'nombre' => '3x8',
                'hora_entrada' => null,
                'hora_salida' => null,
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [0, 1, 2, 3, 4, 5, 6], // Todos
                'dias_descanso' => [],
            ],
            [
                'clave' => '4X3',
                'nombre' => '4x3',
                'hora_entrada' => '08:00',
                'hora_salida' => '20:00',
                'hora_inicio_comida' => '14:00',
                'hora_fin_comida' => '15:00',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4], // Lun-Jue
                'dias_descanso' => [5, 6, 0], // Vie-Dom
            ],
            [
                'clave' => 'FLEX',
                'nombre' => 'Flexible',
                'hora_entrada' => null,
                'hora_salida' => null,
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5],
                'dias_descanso' => [0, 6],
            ],
            [
                'clave' => 'GUAR',
                'nombre' => 'Guardias',
                'hora_entrada' => null,
                'hora_salida' => null,
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [0, 6], // Dom-Sáb
                'dias_descanso' => [1, 2, 3, 4, 5],
            ],
            [
                'clave' => 'EVEN',
                'nombre' => 'Eventual',
                'hora_entrada' => null,
                'hora_salida' => null,
                'hora_inicio_comida' => null,
                'hora_fin_comida' => null,
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [],
                'dias_descanso' => [],
            ],
            [
                'clave' => 'ADM10',
                'nombre' => 'Administrativo 10h',
                'hora_entrada' => '08:00',
                'hora_salida' => '18:00',
                'hora_inicio_comida' => '13:00',
                'hora_fin_comida' => '14:00',
                'entra_dia_anterior' => false,
                'sale_dia_siguiente' => false,
                'dias_laborables' => [1, 2, 3, 4, 5],
                'dias_descanso' => [0, 6],
            ],
        ];

        foreach ($empresas as $empresa) {
            foreach ($turnos as $turnoData) {
                // Insertar turno
                DB::table('turnos')->updateOrInsert(
                    [
                        'firebird_empresa' => $empresa,
                        'nombre' => $turnoData['nombre'],
                    ],
                    [
                        'clave' => $turnoData['clave'],
                        'hora_entrada' => $turnoData['hora_entrada'],
                        'hora_salida' => $turnoData['hora_salida'],
                        'hora_inicio_comida' => $turnoData['hora_inicio_comida'],
                        'hora_fin_comida' => $turnoData['hora_fin_comida'],
                        'entra_dia_anterior' => $turnoData['entra_dia_anterior'],
                        'sale_dia_siguiente' => $turnoData['sale_dia_siguiente'],
                        'status_id' => 1,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]
                );

                // Obtener ID del turno
                $turnoId = DB::table('turnos')
                    ->where('firebird_empresa', $empresa)
                    ->where('nombre', $turnoData['nombre'])
                    ->value('id');

                // Crear configuración para cada día (0-6)
                for ($dia = 0; $dia <= 6; $dia++) {
                    $esLaborable = in_array($dia, $turnoData['dias_laborables']);
                    $esDescanso = in_array($dia, $turnoData['dias_descanso']);

                    DB::table('turno_dias')->updateOrInsert(
                        [
                            'turno_id' => $turnoId,
                            'dia_semana' => $dia,
                        ],
                        [
                            'es_laborable' => $esLaborable,
                            'es_descanso' => $esDescanso,
                            'hora_entrada' => $turnoData['hora_entrada'],
                            'hora_salida' => $turnoData['hora_salida'],
                            'hora_inicio_comida' => $turnoData['hora_inicio_comida'],
                            'hora_fin_comida' => $turnoData['hora_fin_comida'],
                            'entra_dia_anterior' => $turnoData['entra_dia_anterior'],
                            'sale_dia_siguiente' => $turnoData['sale_dia_siguiente'],
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]
                    );
                }
            }
        }
    }
}