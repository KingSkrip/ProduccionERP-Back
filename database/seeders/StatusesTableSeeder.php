<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Status;

class StatusesTableSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['nombre' => 'Activo', 'descripcion' => 'Usuario activo'],
            ['nombre' => 'Inactivo', 'descripcion' => 'Usuario inactivo'],
            ['nombre' => 'Pendiente', 'descripcion' => 'Tarea creada, esperando asignación o inicio'],
            ['nombre' => 'Asignado', 'descripcion' => 'Tarea asignada a un usuario específico'],
            ['nombre' => 'En proceso', 'descripcion' => 'Tarea en desarrollo activo'],
            ['nombre' => 'En revisión', 'descripcion' => 'Tarea completada, esperando aprobación'],
            ['nombre' => 'Devuelta', 'descripcion' => 'Tarea rechazada, requiere correcciones'],
            ['nombre' => 'Finalizada', 'descripcion' => 'Tarea completada y aprobada'],
            ['nombre' => 'Cancelada', 'descripcion' => 'Tarea cancelada, no se completará'],

        ];

        foreach ($statuses as $status) {
            Status::create($status);
        }
    }
}