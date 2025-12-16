<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepSupervisor extends Model
{
    use HasFactory;

    protected $table = 'Depsupervisores';

    protected $fillable = [
        'departamento_id',
        'user_id',
        'fecha_asignacion'
    ];

    // Relaciones
    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    public function subrol()
    {
        return $this->belongsTo(Subrole::class, 'subrol_id');
    }
}
