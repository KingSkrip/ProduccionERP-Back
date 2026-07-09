<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorPermisoResource;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use App\Services\Checador\ChecadorPermisoService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class ChecadorPermisoController extends Controller
{
    public function __construct(protected ChecadorPermisoService $permisoService) {}

    public function catalogo()
    {
        return response()->json($this->permisoService->catalogo());
    }

    public function solicitar(Request $request)
    {
        $identity = $this->identityAutenticada($request);

        $data = $request->validate([
            'checador_catalogo_permiso_id' => 'required|integer|exists:checador_catalogo_permisos,id',
            'tipo' => 'nullable|in:normal,extraordinario',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => 'nullable|date_format:H:i|after:hora_inicio',
            'motivo' => 'required|string|max:255',
        ]);

        // 👇 la identidad SIEMPRE sale del JWT, nunca del body.
        $data['user_firebird_identity_id'] = $identity->id;

        $permiso = $this->permisoService->solicitar($data);

        return (new ChecadorPermisoResource($permiso))
            ->additional(['message' => $permiso->estado === 'aprobado'
                ? 'Permiso registrado y aprobado automáticamente'
                : 'Permiso solicitado, en espera de aprobación de RH'])
            ->response()
            ->setStatusCode(201);
    }

    public function pendientesRh(Request $request)
    {
        $pendientes = $this->permisoService->pendientesRh($request->query('firebird_empresa'));

        return ChecadorPermisoResource::collection($pendientes);
    }

    public function pendientesJefe(Request $request, int $jefeId)
    {
        $pendientes = $this->permisoService->pendientesJefe($jefeId);

        return ChecadorPermisoResource::collection($pendientes);
    }

    // 👇 recibe $rol desde la ruta; el aprobador sale del JWT.
    public function resolver(Request $request, int $permisoId, string $rol)
    {
        $identity = $this->identityAutenticada($request);

        $data = $request->validate([
            'estado' => 'required|in:aprobado,rechazado',
            'comentarios_aprobador' => 'nullable|string|max:500',
        ]);

        // 👇 igual que arriba: quién aprueba lo dice el token, no el body.
        $data['aprobado_por'] = $identity->id;

        try {
            $permiso = $this->permisoService->resolver($permisoId, $rol, $data);

            return (new ChecadorPermisoResource($permiso))
                ->additional(['message' => 'Permiso ' . $data['estado'] . ' por ' . strtoupper($rol)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * Historial de la identidad autenticada (lo que usa el front de
     * "solicitar permiso" para mostrar "Mis permisos").
     */
    public function misPermisos(Request $request)
    {
        $identity = $this->identityAutenticada($request);

        return ChecadorPermisoResource::collection(
            $this->permisoService->historial($identity->id)
        );
    }

    /**
     * Historial de CUALQUIER identidad, para vistas de RH/admin que sí
     * necesitan consultar a otra persona por su id.
     */
    public function historial(int $identityId)
    {
        return ChecadorPermisoResource::collection(
            $this->permisoService->historial($identityId)
        );
    }

    /**
     * Identidad autenticada a partir del JWT.
     *
     * ⚠️ AJUSTA ESTO a como realmente resuelve tu guard 'jwt.auth':
     * - Si $request->user() ya regresa un UserFirebirdIdentity, esta
     *   línea está bien tal cual.
     * - Si regresa otro modelo (p.ej. un User con relación a la
     *   identidad activa), cámbialo por algo como:
     *   return $request->user()->identity;
     *   o resuelve la identidad según el claim que traiga tu token
     *   (empresa/empleado) contra la tabla users_firebird_identities.
     */
    private function identityAutenticada(Request $request): UserFirebirdIdentity
    {
        $token = $request->bearerToken();

        if (!$token) {
            abort(401, 'Token requerido');
        }

        $decoded = JWT::decode(
            $token,
            new Key(config('jwt.secret'), 'HS256')
        );

        $usuario = Users::find((int) $decoded->sub);

        if (!$usuario) {
            abort(404, 'Usuario no encontrado');
        }

        $identity = UserFirebirdIdentity::where(
            'firebird_user_clave',
            (int) $usuario->ID
        )->first();

        if (!$identity) {
            $identity = UserFirebirdIdentity::where(
                'firebird_user_clave',
                (int) $usuario->CLAVE
            )->first();
        }

        if (!$identity) {
            abort(404, 'Identidad no encontrada');
        }

        return $identity;
    }
}