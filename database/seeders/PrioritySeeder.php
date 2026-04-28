<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Priority;

class PrioritySeeder extends Seeder
{
    public function run(): void
    {
        Priority::insert([
            [
                'name'       => 'Baja',
                'slug'       => 'low',
                'color'      => '#22c55e',
                'level'      => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Media',
                'slug'       => 'medium',
                'color'      => '#3b82f6',
                'level'      => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Alta',
                'slug'       => 'high',
                'color'      => '#f59e0b',
                'level'      => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Crítica',
                'slug'       => 'critical',
                'color'      => '#ef4444',
                'level'      => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}