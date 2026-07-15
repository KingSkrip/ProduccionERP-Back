<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorPermisoResource;
use App\Models\ChecadorCatalogoPermiso;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use App\Services\Checador\ChecadorPermisoService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ChecadorPermisoController extends Controller
{
    public function __construct(protected ChecadorPermisoService $permisoService) {}
    private const CLAVES_PAGO_TIEMPO = ['EXTRA', 'PERSONAL', 'TRAMITE', 'MEDICO'];
    public function catalogo()
    {
        return ChecadorCatalogoPermiso::query()
            ->where('activo', 1)
            ->orderBy('orden')
            ->get();
    }


    public function solicitar(Request $request)
    {
        $identity = $this->identityAutenticada($request);

        $catalogo = ChecadorCatalogoPermiso::find($request->input('checador_catalogo_permiso_id'));
        $requierePagoTiempo = $catalogo && in_array($catalogo->clave, self::CLAVES_PAGO_TIEMPO, true);

        $data = $request->validate([
            'checador_catalogo_permiso_id' => 'required|integer|exists:checador_catalogo_permisos,id',
            'tipo' => 'nullable|in:normal,extraordinario',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => [
                'nullable',
                Rule::requiredIf(fn() => !$request->boolean('no_regresa')),
                'date_format:H:i',
                'after:hora_inicio',
            ],
            'no_regresa' => 'required|boolean',
            'motivo' => 'required|string|max:255',

            'tipo_pago_tiempo' => [
                Rule::requiredIf($requierePagoTiempo),
                'nullable',
                'in:tiempo_por_tiempo,dia_descanso,sin_goce',
            ],
            'fecha_reposicion' => [
                'nullable',
                Rule::requiredIf(fn() => $requierePagoTiempo && $request->input('tipo_pago_tiempo') === 'dia_descanso'),
                'date',
                'after:fecha_fin',
            ],
            'hora_inicio_reposicion' => [
                'nullable',
                'date_format:H:i',
            ],
            'hora_fin_reposicion' => [
                'nullable',
                'date_format:H:i',
                'after:hora_inicio_reposicion',
            ],
            'justificacion_pago_tiempo' => 'nullable|string|max:255',
        ]);


        if ($request->input('tipo') === 'extraordinario') {
            $identity = $this->identityAutenticada($request);
            if (!$identity->puede_solicitar_extraordinario) {
                return response()->json(['message' => 'No tienes privilegios para solicitar permisos extraordinarios'], 403);
            }
        }

        // si el tipo de permiso no aplica pago de tiempo, ignoramos cualquier basura que haya mandado el front
        if (!$requierePagoTiempo) {
            unset(
                $data['tipo_pago_tiempo'],
                $data['fecha_reposicion'],
                $data['hora_inicio_reposicion'],
                $data['hora_fin_reposicion'],
                $data['justificacion_pago_tiempo'],
            );
        }

        $data['user_firebird_identity_id'] = $identity->id;

        $permiso = $this->permisoService->solicitar($data);


        return (new ChecadorPermisoResource($permiso))
            ->additional(['message' => $permiso->estado === 'aprobado'
                ? 'Permiso registrado y aprobado automáticamente'
                : 'Permiso solicitado, en espera de aprobación de tu jefe'])
            ->response()
            ->setStatusCode(201);
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


    public function historialEquipo(Request $request, int $jefeId)
    {
        $historial = $this->permisoService->historialEquipo($jefeId);

        return ChecadorPermisoResource::collection($historial);
    }
}