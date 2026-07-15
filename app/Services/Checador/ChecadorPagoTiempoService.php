<?php

namespace App\Services\Checador;

use App\Models\ChecadorPermiso;
use App\Models\ChecadorPermisoPago;
use App\Models\ChecadorRegistro;
use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChecadorPagoTiempoService
{
    /**
     * Busca si hay un permiso tiempo_por_tiempo pendiente de pago para HOY.
     */
    public function permisoPendientePago(UserFirebirdIdentity $identity, string $hoy): ?ChecadorPermiso
    {
        return ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('estado', 'aprobado')
            ->where('tipo_pago_tiempo', 'tiempo_por_tiempo')
            ->whereColumn('minutos_pagados', '<', 'minutos_ausencia')
            ->whereDate('fecha_reposicion', $hoy)
            ->orderBy('fecha_reposicion')
            ->first();
    }

    /**
     * Abona minutos de anticipación (entrada) o de exceso (salida) a la deuda del permiso.
     * Regresa cuántos minutos quedaron SIN abonar (para que el llamador los cuente como
     * retardo/hora extra normales).
     */
    public function abonar(
        ChecadorPermiso $permiso,
        UserFirebirdIdentity $identity,
        int $minutosDisponibles,
        string $origen,
        string $hoy,
        ?int $registroId = null
    ): int {
        if ($minutosDisponibles <= 0) {
            return 0;
        }

        $pendiente = $permiso->minutos_pendientes;
        $aAbonar = min($pendiente, $minutosDisponibles);

        if ($aAbonar <= 0) {
            return $minutosDisponibles;
        }

        DB::transaction(function () use ($permiso, $identity, $aAbonar, $origen, $hoy, $registroId) {
            ChecadorPermisoPago::create([
                'checador_permiso_id' => $permiso->id,
                'checador_registro_id' => $registroId,
                'user_firebird_identity_id' => $identity->id,
                'origen' => $origen,
                'minutos_abonados' => $aAbonar,
                'fecha' => $hoy,
            ]);

            $permiso->increment('minutos_pagados', $aAbonar);

            if ($permiso->fresh()->minutos_pendientes <= 0) {
                $permiso->update([
                    'tiempo_pagado' => true,
                    'fecha_tiempo_pagado' => now(),
                    'pagado_en_registro_id' => $registroId,
                ]);
            }
        });

        Log::info('PAGO_TIEMPO_ABONADO', [
            'permiso_id' => $permiso->id,
            'identity_id' => $identity->id,
            'origen' => $origen,
            'minutos_abonados' => $aAbonar,
        ]);

        return $minutosDisponibles - $aAbonar;
    }
}