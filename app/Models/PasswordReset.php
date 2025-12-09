<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PasswordReset extends Model
{
    protected $table = 'password_resets';

    protected $primaryKey = 'id';
    public $incrementing = true;
    public $keyType = 'int';

    public $timestamps = true; // Usamos timestamps automáticos
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null; // No necesitamos updated_at

    protected $fillable = [
        'email',
        'token',
        'usuario_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Relación con Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(Users::class, 'usuario_id', 'id');
    }
}
