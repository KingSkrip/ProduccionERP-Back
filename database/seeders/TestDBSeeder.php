<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDBSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        /* ==========================================
         * DEPARTAMENTOS
         * ========================================== */
        DB::table('departamentos')->insert([
            ['id'=>1,'nombre'=>'PESADO ACABADO'],
            ['id'=>2,'nombre'=>'PESADO RAMAS'],
            ['id'=>3,'nombre'=>'PESADO PRODUCTO TERMINADO'],
            ['id'=>4,'nombre'=>'PESADO HILATURA'],
            ['id'=>5,'nombre'=>'HILATURA'],
            ['id'=>6,'nombre'=>'CONTROL DE CALIDAD'],
            ['id'=>7,'nombre'=>'ACABADO'],
            ['id'=>8,'nombre'=>'TEJIDO'],
            ['id'=>9,'nombre'=>'TINTORERIA'],
            ['id'=>10,'nombre'=>'ALMACEN DE TELA EN CRUDO'],
            ['id'=>11,'nombre'=>'PROGRAMACION Y PLANEACION'],
            ['id'=>12,'nombre'=>'ALMACEN GENERAL'],
            ['id'=>13,'nombre'=>'ACABADO TUBULAR'],
            ['id'=>14,'nombre'=>'ALMACEN TELA ACABADA PT'],
            ['id'=>15,'nombre'=>'SIN ASIGNAR 02'],
            ['id'=>16,'nombre'=>'ESTAMPADO'],
            ['id'=>17,'nombre'=>'MANTENIMIENTO'],
            ['id'=>18,'nombre'=>'SIN ASIGNAR 03'],
            ['id'=>19,'nombre'=>'PREPARACION'],
            ['id'=>20,'nombre'=>'TEJIDO PLANO'],
            ['id'=>21,'nombre'=>'VIGILANCIA'],
            ['id'=>22,'nombre'=>'NINGUNO'],
            ['id'=>23,'nombre'=>'LABORATORIO'],
            ['id'=>24,'nombre'=>'OFICINAS'],
            ['id'=>25,'nombre'=>'MANTENIMIENTO Y SERVICIOS'],
            ['id'=>26,'nombre'=>'SIN ASIGNAR 04'],
            ['id'=>27,'nombre'=>'DESARROLLOS'],
            ['id'=>28,'nombre'=>'SIN ASIGNAR 05'],
            ['id'=>29,'nombre'=>'AGENTE DE VENTAS'],
            ['id'=>30,'nombre'=>'VENTAS'],
            ['id'=>31,'nombre'=>'ADMINISTRACION N1'],
            ['id'=>32,'nombre'=>'ADMINISTRACION N2'],
        ]);

        /* ==========================================
         * STATUSES
         * ========================================== */
        DB::table('statuses')->insert([
            ['id'=>1,'nombre'=>'Activo','descripcion'=>'Usuario activo'],
            ['id'=>2,'nombre'=>'Inactivo','descripcion'=>'Usuario inactivo'],
        ]);

        /* ==========================================
         * ROLES
         * ========================================== */
        DB::table('roles')->insert([
            ['id'=>1, 'nombre'=>'COLABORADOR', 'GUARD_NAME'=>'web'],
            ['id'=>2, 'nombre'=>'RH',          'GUARD_NAME'=>'web'],
            ['id'=>3, 'nombre'=>'SUADMIN',     'GUARD_NAME'=>'web'],
            ['id'=>4, 'nombre'=>'ADMIN',       'GUARD_NAME'=>'web'],
        ]);

        /* ==========================================
         * SUBROLES
         * ========================================== */
        DB::table('subroles')->insert([
            ['id'=>1, 'nombre'=>'OPERARIO', 'GUARD_NAME'=>'web'],
            ['id'=>2, 'nombre'=>'SUPERVISOR', 'GUARD_NAME'=>'web'],
            ['id'=>3, 'nombre'=>'GERENTE', 'GUARD_NAME'=>'web'],
            ['id'=>4, 'nombre'=>'CONTADOR', 'GUARD_NAME'=>'web'],
            ['id'=>5, 'nombre'=>'AUXILIAR ADMINISTRATIVO', 'GUARD_NAME'=>'web'],
        ]);

        /* ==========================================
         * DIRECCIONES
         * ========================================== */
        DB::table('direcciones')->insert([
            [
                'id'=>5,
                'calle'=>'sdfasf',
                'no_ext'=>'sdafasf',
                'no_int'=>'asdfas',
                'colonia'=>'DE LA VERACRUZ',
                'cp'=>'51356',
                'municipio'=>'ZINACANTEPEC',
                'estado'=>'MEXICO',
            ]
        ]);

        /* ==========================================
         * USERS
         * ========================================== */
        DB::table('users')->insert([
            [
                'id'=>1,
                'nombre'=>'Super Admin',
                'usuario'=>'superadmin',
                'curp'=>'XEXX010101HNEXXXA8',
                'telefono'=>'7221234567',
                'correo'=>'super@admin.com',
                'password'=>Hash::make('12345678'),
                'photo'=>'photos/users.jpg',
                'status_id'=>1,
                'departamento_id'=>1,
                'direccion_id'=>5,
            ],
            [
                'id'=>2,
                'nombre'=>'Empleado Demo',
                'usuario'=>'empleado01',
                'curp'=>'XEXX010101HDFXXXA9',
                'telefono'=>'7227654321',
                'correo'=>'empleado@demo.com',
                'password'=>Hash::make('12345678'),
                'photo'=>'photos/users.jpg',
                'status_id'=>1,
                'departamento_id'=>2,
                'direccion_id'=>5,
            ],
        ]);

        /* ==========================================
         * USER FISCAL
         * ========================================== */
        DB::table('user_fiscals')->insert([
            ['user_id'=>1,'rfc'=>'PEVA000101AAA','regimen_fiscal'=>'612 – Personas Físicas'],
            ['user_id'=>2,'rfc'=>'PEVA000102BBB','regimen_fiscal'=>'601 – General de Ley PM'],
        ]);

        /* ==========================================
         * USER EMPLEOS
         * ========================================== */
        DB::table('user_empleos')->insert([
            ['user_id'=>1,'puesto'=>'SUPERADMIN', 'fecha_inicio'=>'2024-01-01'],
            ['user_id'=>2,'puesto'=>'OPERARIO',    'fecha_inicio'=>'2024-02-01'],
        ]);

        /* ==========================================
         * USER NOMINAS
         * ========================================== */
        DB::table('user_nominas')->insert([
            [
                'user_id'=>1,
                'numero_tarjeta'=>'5256789001234567',
                'banco'=>'BBVA',
                'clabe_interbancaria'=>'012345678901234567',
                'salario_base'=>25000,
                'frecuencia_pago'=>'Quincenal'
            ],
            [
                'user_id'=>2,
                'numero_tarjeta'=>'5256789009876543',
                'banco'=>'Santander',
                'clabe_interbancaria'=>'002987654321098765',
                'salario_base'=>8500,
                'frecuencia_pago'=>'Semanal'
            ]
        ]);

        /* ==========================================
         * USER SEGURIDAD SOCIAL
         * ========================================== */
        DB::table('user_seguridad_socials')->insert([
            [
                'user_id'=>1,
                'numero_imss'=>'12345678901',
                'fecha_alta'=>'2024-01-01',
                'tipo_seguro'=>'IMSS Obligatorio'
            ],
            [
                'user_id'=>2,
                'numero_imss'=>'98765432109',
                'fecha_alta'=>'2024-02-01',
                'tipo_seguro'=>'IMSS Obligatorio'
            ]
        ]);

        /* ==========================================
         * MODEL_HAS_ROLES
         * ========================================== */
        DB::table('model_has_roles')->insert([
            ['id'=>1, 'ROLE_CLAVE'=>3, 'MODEL_CLAVE'=>1, 'SUBROL_ID'=>null, 'MODEL_TYPE'=>'App\\Models\\Users'],
            ['id'=>2, 'ROLE_CLAVE'=>1, 'MODEL_CLAVE'=>2, 'SUBROL_ID'=>null, 'MODEL_TYPE'=>'App\\Models\\Users'],
        ]);

        /* ==========================================
         * ASISTENCIAS
         * ========================================== */
        DB::table('asistencias')->insert([
            [
                'id'=>1,
                'user_id'=>1,
                'fecha'=>'2025-12-01',
                'hora_entrada'=>'07:00:00',
                'hora_salida'=>'08:00:00',
            ]
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
}
