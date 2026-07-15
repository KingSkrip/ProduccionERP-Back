<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorRegistroResource;
use App\Models\ChecadorRegistro;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use App\Services\Checador\ChecadorScanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ChecadorController extends Controller
{
    public function __construct(protected ChecadorScanService $scanService) {}

    /**
     * Busca empleados en Firebird por clave o nombre, para el checador
     * manual (cuando no hay QR a la mano). Indica si ya tienen
     * user_firebird_identity creado (requisito para poder checar).
     */
    public function buscarEmpleado(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $termino = Str::upper(trim($request->query('q')));

        $empleados = Users::query()
            ->where(function ($query) use ($termino) {
                $query->where('CLAVE', 'like', "%{$termino}%")
                    ->orWhere('NOMBRE', 'like', "%{$termino}%");
            })
            ->limit(20)
            ->get();

        $resultado = $empleados->map(function ($empleado) {
            $clave = trim((string) $empleado->CLAVE);

            $identity = UserFirebirdIdentity::where('firebird_user_clave', $clave)->first();

            return [
                'firebird_clave' => $clave,
                'nombre' => trim((string) $empleado->NOMBRE),
                'identity_id' => $identity->id ?? null,
                'tiene_identity' => (bool) $identity,
            ];
        });

        return response()->json($resultado->values());
    }

    /**
     * Registro MANUAL de checada (sin QR): para cuando el empleado no
     * trae su QR o el lector falla y un admin captura la entrada/salida
     * a mano. Aplica las mismas reglas de tolerancia/permiso que el QR.
     */
    public function registrarManual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_firebird_identity_id' => 'required|integer|exists:users_firebird_identities,id',
            'observaciones' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $resultado = $this->scanService->registrarChecadaManual(
                (int) $request->input('user_firebird_identity_id'),
                [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'observaciones_extra' => $request->input('observaciones'),
                ]
            );

            Log::info('✍️ CHECADA_MANUAL_REGISTRADA', [
                'identity_id' => $request->input('user_firebird_identity_id'),
                'registrado_por' => $request->user()->id ?? null,
            ]);

            return (new ChecadorRegistroResource($resultado))
                ->response()
                ->setStatusCode(201);
      } catch (\Illuminate\Database\QueryException $e) {
    Log::error('DB_ERROR_CHECADA', ['error' => $e->getMessage()]);
    return response()->json(['message' => 'Error interno al procesar la operación'], 500);
} catch (\RuntimeException $e) {
    $codigo = $e->getCode();
    $status = (is_int($codigo) && $codigo >= 100 && $codigo < 600) ? $codigo : 500;
    return response()->json(['message' => $e->getMessage()], $status);
}
    }

    /**
     * Dashboard en vivo: todas las checadas de hoy, opcionalmente
     * filtradas por empresa. Para el panel del checador.
     */
    public function hoy(Request $request)
    {
        $hoy = Carbon::now()->toDateString();

        $query = ChecadorRegistro::with(['identity.firebirdUser'])
            ->where('fecha', $hoy)
            ->orderByDesc('fecha_hora');

        if ($empresa = $request->query('firebird_empresa')) {
            $query->where('firebird_empresa', $empresa);
        }

        $registros = $query->get()->map(function (ChecadorRegistro $registro) {
            return [
                'id' => $registro->id,
                'identity_id' => $registro->user_firebird_identity_id,
                'nombre' => trim((string) ($registro->identity->firebirdUser->NOMBRE ?? '')),
                'tipo' => $registro->tipo,
                'hora' => $registro->hora,
                'metodo' => $registro->metodo,
                'valido' => $registro->valido,
                'observaciones' => $registro->observaciones,
            ];
        });

        return response()->json([
            'fecha' => $hoy,
            'total' => $registros->count(),
            'registros' => $registros->values(),
        ]);
    }
}