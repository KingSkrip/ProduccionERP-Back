<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Models\UserFirebirdIdentity;
use Illuminate\Http\Request;

class ChecadorIdentidadController extends Controller
{
    public function toggleAjusteSalida(Request $request, int $identityId)
    {
        $data = $request->validate([
            'checador_ajuste_salida_puntual' => 'required|boolean',
        ]);

        $identity = UserFirebirdIdentity::findOrFail($identityId);
        $identity->update($data);

        return response()->json([
            'message' => 'Ajuste de salida actualizado',
            'checador_ajuste_salida_puntual' => $identity->checador_ajuste_salida_puntual,
        ]);
    }

    public function asignarCredencial(Request $request, int $identityId)
    {
        $data = $request->validate([
            'numero_credencial' => 'required|string|max:30|unique:users_firebird_identities,numero_credencial,' . $identityId,
        ]);

        $identity = UserFirebirdIdentity::findOrFail($identityId);
        $identity->update($data);

        return response()->json(['message' => 'Credencial asignada', 'numero_credencial' => $identity->numero_credencial]);
    }
}