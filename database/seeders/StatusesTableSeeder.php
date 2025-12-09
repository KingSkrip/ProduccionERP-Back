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
        ];

        foreach ($statuses as $status) {
            Status::create($status);
        }
    }
}
