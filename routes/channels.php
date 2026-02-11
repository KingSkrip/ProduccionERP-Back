<?php

use App\Models\UserFirebirdIdentity;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;


Broadcast::routes(['middleware' => ['api', App\Http\Middleware\JwtAuth::class]]);

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Canal privado para cada usuario (basado en su identityId)
// Broadcast::channel('user.{identityId}', function ($user, $identityId) {
//     // Obtener el identityId del usuario autenticado
//     $firebirdIdentity = UserFirebirdIdentity::where('firebird_user_clave', $user->id)->first();

//     // Solo puede escuchar su propio canal
//     return $firebirdIdentity && (int) $firebirdIdentity->id === (int) $identityId;
// });

// 🔥 OPCIONAL: Canal de presencia para ver quién está online
Broadcast::channel('workorder.{workorderId}', function ($user, $workorderId) {
    $firebirdIdentity = \App\Models\UserFirebirdIdentity::where('firebird_user_clave', $user->id)->first();

    if (!$firebirdIdentity) {
        return false;
    }

    // Verificar si el usuario tiene acceso a este workorder
    $workorder = \App\Models\WorkOrder::find($workorderId);

    if (!$workorder) {
        return false;
    }

    // Tiene acceso si es de, para, o participant
    $hasAccess = $workorder->de_id === $firebirdIdentity->id
        || $workorder->para_id === $firebirdIdentity->id
        || $workorder->taskParticipants()->where('user_id', $firebirdIdentity->id)->exists();

    if ($hasAccess) {
        // Retornar info del usuario para presencia
        return [
            'id' => $firebirdIdentity->id,
            'name' => $firebirdIdentity->firebirdUser->nombre ?? 'Usuario',
        ];
    }

    return false;
});



Broadcast::channel('user.{identityId}', function ($user, $identityId) {
    Log::info('🚨 BROADCAST AUTH', [
        'request_user' => [
            'class' => get_class($user),
            'id' => $user->id ?? $user->ID ?? null,
            'all' => method_exists($user, 'toArray') ? $user->toArray() : 'no toArray',
        ],
        'requested_identity_id' => $identityId,
    ]);

    $userId = $user->id ?? $user->ID;

    if (!$userId) {
        Log::error('❌ No user ID found');
        return false;
    }

    Log::info('🔍 Looking for identity', [
        'user_id' => $userId,
        'looking_for_identity_id' => $identityId,
    ]);

    // Buscar la identidad usando el ID del usuario de Firebird
    $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first();

    if (!$identity) {
        Log::error('❌ No identity found for user', [
            'user_id' => $userId,
        ]);
        return false;
    }

    Log::info('✅ Identity found', [
        'identity_id' => $identity->id,
        'firebird_user_clave' => $identity->firebird_user_clave,
        'requested_channel_identity' => $identityId,
        'match' => (int) $identity->id === (int) $identityId,
    ]);

    // Verificar que el identity_id del usuario coincida con el canal solicitado
    return (int) $identity->id === (int) $identityId;
});