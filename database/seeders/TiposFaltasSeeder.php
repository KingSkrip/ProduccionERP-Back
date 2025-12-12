<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Faltas;
use App\Models\TipoFalta;
use App\Models\TiposFaltas;

class TiposFaltasSeeder extends Seeder
{
    public function run(): void
    {
        $faltas = [
            ['descripcion' => 'Incapacidad'],
            ['descripcion' => 'Ausentismo'],
            ['descripcion' => 'Tipo de falta 3'],
            ['descripcion' => 'Tipo de falta 4'],
            ['descripcion' => 'Tipo de falta 5'],
            ['descripcion' => 'Tipo de falta 6'],
            ['descripcion' => 'Tipo de falta 7'],
            ['descripcion' => 'Tipo de falta 8'],
            ['descripcion' => 'Ausent. sin 7o. dia'],
            ['descripcion' => 'Tipo de falta 10'],
            ['descripcion' => 'Tipo de falta 11'],
            ['descripcion' => 'Tipo de falta 12'],
            ['descripcion' => 'Tipo de falta 13'],
            ['descripcion' => 'Tipo de falta 14'],
            ['descripcion' => 'Tipo de falta 15'],
            ['descripcion' => 'Tipo de falta 16'],
            ['descripcion' => 'Incap. temporal R.T.'],
            ['descripcion' => 'Incap. perm. parc. R.T.'],
            ['descripcion' => 'Incap. perm. total R.T.'],
        ];

        foreach ($faltas as $falta) {
            TipoFalta::create($falta);
        }
    }
}
