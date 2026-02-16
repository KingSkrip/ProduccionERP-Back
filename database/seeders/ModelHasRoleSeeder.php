<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ModelHasRole;

class ModelHasRoleSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            // id, role_id, subrol_id, firebird_identity_id, model_type
            [337, 3, 6, 337, 'firebird_identity'],
            [170, 3, 6, 170, 'firebird_identity'],
            [234, 3, 6, 234, 'firebird_identity'],
            [253, 3, null, 253, 'firebird_identity'],
            [284, 3, 6, 284, 'firebird_identity'],
            [303, 3, 6, 303, 'firebird_identity'],
        ];

        foreach ($data as [$id, $roleId, $subrolId, $firebirdId, $modelType]) {
            ModelHasRole::updateOrCreate(
                ['id' => $id], // 🔥 ahora busca por ID
                [
                    'role_id' => $roleId,
                    'subrol_id' => $subrolId,
                    'firebird_identity_id' => $firebirdId,
                    'model_type' => $modelType,
                ]
            );
        }
    }
}