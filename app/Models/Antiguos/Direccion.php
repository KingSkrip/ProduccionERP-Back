<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Firebird\Users;

class Direccion extends Model
{
    protected $table = 'direcciones';

    protected $fillable = [
        'calle',
        'no_ext',
        'no_int',
        'colonia',
        'cp',
        'municipio',
        'estado',
        'entidad_federativa',
        'pais',
    ];

    /**
     * Relación opcional con User
     */
    public function user(): HasOne
    {
        return $this->hasOne(Users::class, 'direccion_id'); // si agregas campo direccion_id en users
    }
}
