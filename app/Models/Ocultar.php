<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ocultar extends Model
{
    use HasFactory;

    protected $table = 'ocultar';

    protected $fillable = [
        'user_id',
        'z200_id',
        'oculto',
    ];

    protected $casts = [
        'oculto' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    // Relación con el usuario
    public function user()
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'user_id'
        );
    }
}