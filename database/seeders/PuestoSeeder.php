<?php

namespace Database\Seeders;

use App\Models\Puesto;
use Illuminate\Database\Seeder;

class PuestoSeeder extends Seeder
{
    public function run(): void
    {
        $puestos = [

            // ===================== GERENTES DE ÁREA (máximo nivel por área) =====================
            [
                'nombre' => 'Gerente de Seguridad',
                'descripcion' => 'Gerente del área de Seguridad',
                'es_gerente' => true,
            ],
            [
                'nombre' => 'Gerente de Recursos Humanos',
                'descripcion' => 'Gerente del área de Recursos Humanos',
                'es_gerente' => true,
                'es_rh' => true, // 👈 el único que de verdad es RH
            ],
            [
                'nombre' => 'Gerente de Recepción de Materiales',
                'descripcion' => 'Gerente del área de Recepción de Materiales',
                'es_gerente' => true,
            ],
            [
                'nombre' => 'Gerente de Sistemas',
                'descripcion' => 'Gerente del área de Sistemas',
                'es_gerente' => true,
            ],
            [
                'nombre' => 'Gerente de Tejido',
                'descripcion' => 'Gerente del área de Tejido',
                'es_gerente' => true,
            ],
            [
                'nombre' => 'Gerente de Producción',
                'descripcion' => 'Gerente del área de Producción',
                'es_gerente' => true,
            ],
            [
                'nombre' => 'Gerente de Mantenimiento',
                'descripcion' => 'Gerente del área de Mantenimiento',
                'es_gerente' => true,
            ],

            // ===================== JEFES / COORDINADORES (mando medio, aprueban como "jefe directo") =====================
            [
                'nombre' => 'Coordinador de Sistemas',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefa de Seguridad Patrimonial',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefe de Producción Tejido',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefe de Mantenimiento Tejido',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Coordinador de Tejido',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Responsable de Laboratorio de Colorimetría',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefe de Tintorería',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefa de Calidad de Acabado',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefe de Almacén de Crudo',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefe de Mantenimiento',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            [
                'nombre' => 'Jefe de Embarque',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],

            // ===================== PUESTOS OPERATIVOS DE SISTEMAS (no jefean a nadie) =====================
            ['nombre' => 'Administrador de Sistemas', 'descripcion' => null],
            ['nombre' => 'Administrador de Redes', 'descripcion' => null],
            ['nombre' => 'Administrador de Base de Datos', 'descripcion' => null],
            ['nombre' => 'Desarrollador Senior', 'descripcion' => null],
            ['nombre' => 'Desarrollador Semi Senior', 'descripcion' => null],
            ['nombre' => 'Desarrollador Junior', 'descripcion' => null],
            ['nombre' => 'Programador', 'descripcion' => null],
            ['nombre' => 'Analista de Sistemas', 'descripcion' => null],
            ['nombre' => 'Analista Programador', 'descripcion' => null],
            ['nombre' => 'Soporte Técnico', 'descripcion' => null],
            ['nombre' => 'Auxiliar de Sistemas', 'descripcion' => null],
            ['nombre' => 'Técnico en Sistemas', 'descripcion' => null],
            ['nombre' => 'Técnico de Redes', 'descripcion' => null],
            ['nombre' => 'Mesa de Ayuda', 'descripcion' => null],
            ['nombre' => 'Operador de Sistemas', 'descripcion' => null],
            ['nombre' => 'Administrador ERP', 'descripcion' => null],
            ['nombre' => 'Administrador de Infraestructura', 'descripcion' => null],
            ['nombre' => 'Especialista en Ciberseguridad', 'descripcion' => null],
            ['nombre' => 'Ingeniero de Software', 'descripcion' => null],
            ['nombre' => 'Ingeniero de Infraestructura', 'descripcion' => null],
            ['nombre' => 'Residente de Sistemas', 'descripcion' => null],
            ['nombre' => 'Practicante de Sistemas', 'descripcion' => null],
            ['nombre' => 'Becario de Sistemas', 'descripcion' => null],

            // ===================== SEGURIDAD / PREVENCIÓN DE PÉRDIDAS =====================
            ['nombre' => 'Prevención de Pérdidas', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Supervisor de Prevención de Pérdidas', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Supervisor de Seguridad e Higiene', 'descripcion' => null, 'es_subordinado' => true],

            // ===================== LABORATORIO / MATERIA PRIMA =====================
            ['nombre' => 'Encargado de Laboratorio', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Analista de Materia Prima', 'descripcion' => null, 'es_subordinado' => true],

            // ===================== SALUD =====================
            ['nombre' => 'Enfermera/o', 'descripcion' => null, 'es_subordinado' => true],

            // ===================== RECURSOS HUMANOS =====================
            ['nombre' => 'Auxiliar de RH', 'descripcion' => null, 'es_rh' => true],
            ['nombre' => 'Reclutamiento', 'descripcion' => null, 'es_rh' => true],

            // ===================== FACTURACIÓN / CONTABILIDAD =====================
            ['nombre' => 'Encargada de Facturación', 'descripcion' => null, 'es_subordinado' => true],
            [
                'nombre' => 'Gerente de Contabilidad',
                'descripcion' => null,
                'es_gerente' => true,
            ],
            ['nombre' => 'Contador', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Encargada de Crédito y Cobranza', 'descripcion' => null, 'es_subordinado' => true],

            // ===================== VENTAS =====================
            [
                'nombre' => 'Jefe de Planeación y Ventas',
                'descripcion' => null,
                'es_jefe_area' => true,
            ],
            ['nombre' => 'Auxiliar de Ventas', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Programador de Ventas', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Muestrista', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Recepcionista', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Comprador', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Gerente de Ventas de Exportación', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Agente de Ventas Nacionales', 'descripcion' => null, 'es_subordinado' => true],
            ['nombre' => 'Marketing', 'descripcion' => null, 'es_subordinado' => true],
        ];

        foreach ($puestos as $puesto) {
            Puesto::updateOrCreate(
                ['nombre' => $puesto['nombre']],
                [
                    'descripcion'    => $puesto['descripcion'] ?? null,
                    'es_gerente'     => $puesto['es_gerente'] ?? false,
                    'es_jefe_area'   => $puesto['es_jefe_area'] ?? false,
                    'es_rh'          => $puesto['es_rh'] ?? false,
                    'es_subordinado' => $puesto['es_subordinado'] ?? false,
                    'activo'         => true,
                ]
            );
        }
    }
}