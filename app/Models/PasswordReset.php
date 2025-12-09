<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PasswordReset extends Model
{
    protected $table = 'PASSWORD_RESET';

    protected $primaryKey = 'ID';

    // 🔥 Es autoincrementable mediante generator + trigger en Firebird
    public $incrementing = true;
    public $keyType = 'int';

    public $timestamps = false; // No usamos created_at/updated_at automáticos de Laravel

    protected $connection = 'firebird'; // Asegúrate de que el connection esté definido en config/database.php

    protected $fillable = [
        'EMAIL',
        'TOKEN',
        'CREATED_AT',
        'USUARIO_ID',
    ];

    protected $casts = [
        'CREATED_AT' => 'datetime',
    ];

    /**
     * Relación con Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'USUARIO_ID', 'CLAVE');
    }

    /**
     * Sobrescribir la fecha de creación automática si quieres
     */
    public static function boot()
    {
        parent::boot();

        // Asignar fecha actual al crear un nuevo registro
        static::creating(function ($model) {
            if (empty($model->CREATED_AT)) {
                $model->CREATED_AT = Carbon::now();
            }
        });
    }
}
