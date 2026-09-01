<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorRegistroResource;
use App\Models\UserFirebirdIdentity;
use App\Services\Checador\ChecadorGuardiaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ChecadorGuardiaController extends Controller
{
    public function __construct(protected ChecadorGuardiaService $guardiaService) {}

    /**
     * GET /checador/guardia/buscar?q=nombre-o-clave
     * Búsqueda de personas por nombre/clave para el checador manual.
     */
    public function buscar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        return response()->json(
            $this->guardiaService->buscarPorNombre($request->query('q'))
        );
    }

    /**
     * GET /checador/guardia/estado/{identityId}
     * Estado actual del día (para que el front sepa qué botón mostrar:
     * entrada, salida, inicio o fin de permiso).
     */
    public function estado(int $identityId)
    {
        $identity = UserFirebirdIdentity::findOrFail($identityId);

        return response()->json($this->guardiaService->estadoActual($identity));
    }

    /**
     * POST /checador/guardia/registrar
     * Registra manualmente la entrada/salida/permiso de alguien que no
     * trae su QR, no tiene datos, o le robaron/extravió su credencial.
     */
    public function registrar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_firebird_identity_id' => 'required|integer|exists:users_firebird_identities,id',
            'motivo_manual' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $resultado = $this->guardiaService->registrar(
                (int) $request->input('user_firebird_identity_id'),
                $request->input('motivo_manual'),
                $request->user()->id ?? null,
                [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );

            Log::info('CHECADA_GUARDIA_REGISTRADA', [
                'identity_id' => $request->input('user_firebird_identity_id'),
                'guardia_id' => $request->user()->id ?? null,
            ]);

            return (new ChecadorRegistroResource($resultado))
                ->response()
                ->setStatusCode(201);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('DB_ERROR_CHECADA_GUARDIA', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error interno al procesar la operación'], 500);
        } catch (\RuntimeException $e) {
            $codigo = $e->getCode();
            $status = (is_int($codigo) && $codigo >= 100 && $codigo < 600) ? $codigo : 500;
            return response()->json(['message' => $e->getMessage()], $status);
        }
    }
}