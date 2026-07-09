<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiasSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('dias')->insert([
            ['nombre' => 'Lunes', 'orden' => 1],
            ['nombre' => 'Martes', 'orden' => 2],
            ['nombre' => 'Miércoles', 'orden' => 3],
            ['nombre' => 'Jueves', 'orden' => 4],
            ['nombre' => 'Viernes', 'orden' => 5],
            ['nombre' => 'Sábado', 'orden' => 6],
            ['nombre' => 'Domingo', 'orden' => 7],
        ]);
    }
};   