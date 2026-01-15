<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelHasRole extends Model
{

    protected $connection = 'mysql';
    
    protected $table = 'model_has_roles';

    protected $fillable = [
        'role_id',
        'subrol_id',
        'firebird_identity_id',
        'model_type',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'role_id');
    }

    public function subrol(): BelongsTo
    {
        return $this->belongsTo(Subrole::class, 'subrol_id');
    }

    public function firebirdIdentity(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'firebird_identity_id'
        );
    }
}
