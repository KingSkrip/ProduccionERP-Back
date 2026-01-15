<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordReset extends Model
{
    protected $connection = 'mysql';
    protected $table = 'password_resets';
    public $timestamps = false;

    protected $fillable = [
        'email',
        'token',
        'usuario_id',
        'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'usuario_id'
        );
    }
}
