<?php

namespace App\Http\Controllers\Checador;

use App\Models\ChecadorRegistro;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ChecadorController extends Controller
{
    /**
     * Tolerancia en minutos antes de marcar retardo en la entrada.
     */
    private int $toleranciaMinutos = 10;

    /**
     * GET /checador/mi-qr
     * Devuelve (o genera si no existe) el token QR fijo del usuario autenticado.
     * El front puede pintar el QR con cualquier librería JS (ej. qrcode.js)
     * usando este texto como contenido, o pedir la imagen ya generada si
     * tienen instalado simplesoftwareio/simple-qrcode (ver abajo).
     */
    public function miQr(Request $request)
    {
        try {
            $usuario = $request->attributes->get('auth_usuario') ?? $this->resolverUsuarioAutenticado($request);

            if (!$usuario) {
                return response()->json(['message' => 'No autenticado'], 401);
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->ID)->first();

            if (!$identity) {
                return response()->json(['message' => 'El usuario no tiene identidad asignada'], 422);
            }

            if (!$identity->qr_token) {
                $identity->qr_token = $this->generarTokenUnico();
                $identity->save();
            }

            $payload = [
                'qr_token' => $identity->qr_token,
                'nombre'   => $usuario->NOMBRE ? trim((string) $usuario->NOMBRE) : null,
                'photo'    => $usuario->PHOTO ? trim((string) $usuario->PHOTO) : null,
            ];

            // Si tienen instalada la librería de QR, se regresa también la imagen en base64.
            if (class_exists(\SimpleSoftwareIO\QrCode\Facades\QrCode::class)) {
                $payload['qr_base64'] = base64_encode(
                    \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')->size(300)->generate($identity->qr_token)
                );
            }

            return response()->json($payload, 200);
        } catch (\Throwable $e) {
            Log::error('💥 Error en miQr()', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al generar QR'], 500);
        }
    }

    /**
     * POST /checador/scan
     * Endpoint que consume el kiosko/tablet al leer el QR del empleado.
     * Protegido por middleware de dispositivo (API key de la tablet),
     * NO por el JWT del empleado, porque quien "inicia sesión" aquí es
     * el kiosko, no la persona.
     *
     * body: { qr_token: string, dispositivo?: string }
     */
    public function scan(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'qr_token'    => 'required|string',
                'dispositivo' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos inválidos'], 422);
            }

            $identity = UserFirebirdIdentity::where('qr_token', $request->qr_token)->first();

            if (!$identity) {
                Log::warning('❌ CHECADOR_QR_NO_ENCONTRADO', ['qr_token' => $request->qr_token]);
                return response()->json(['message' => 'QR no reconocido'], 404);
            }

            $usuario = Users::find($identity->firebird_user_clave);

            if (!$usuario) {
                Log::warning('❌ CHECADOR_USUARIO_FIREBIRD_NO_ENCONTRADO', [
                    'identity_id' => $identity->id,
                ]);
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }

            $ahora = Carbon::now();
            $hoy = $ahora->toDateString();

            // ¿Ya tiene un registro hoy? Determina si toca entrada o salida.
            $ultimoRegistroHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                ->whereDate('fecha', $hoy)
                ->orderByDesc('fecha_hora')
                ->first();

            $tipo = (!$ultimoRegistroHoy || $ultimoRegistroHoy->tipo === 'salida')
                ? 'entrada'
                : 'salida';

            // Turno activo (misma relación que ya usan en el login)
            $turnoActivo = $identity->turnoActivo()
                ->with(['turno.turnoDias', 'status'])
                ->first();

            $turnoId = null;
            $turnoNombre = null;
            $horaEsperada = null;
            $minutosDiferencia = null;
            $retardo = false;

            if ($turnoActivo && $turnoActivo->turno) {
                $turnoId = $turnoActivo->turno->id ?? null;
                $turnoNombre = $turnoActivo->turno->nombre ?? null;

                $horarioHoy = method_exists($turnoActivo, 'getHorariosHoy')
                    ? $turnoActivo->getHorariosHoy()
                    : null;

                if ($horarioHoy) {
                    $campoHora = $tipo === 'entrada' ? 'hora_entrada' : 'hora_salida';
                    $horaEsperadaRaw = $horarioHoy[$campoHora] ?? null;

                    if ($horaEsperadaRaw) {
                        $horaEsperada = $horaEsperadaRaw;

                        $esperado = Carbon::parse($hoy . ' ' . $horaEsperadaRaw);
                        $minutosDiferencia = $ahora->diffInMinutes($esperado, false) * -1;
                        // positivo = llegó tarde / se fue tarde, negativo = temprano

                        if ($tipo === 'entrada' && $minutosDiferencia > $this->toleranciaMinutos) {
                            $retardo = true;
                        }
                    }
                }
            } else {
                Log::warning('⚠️ CHECADOR_SIN_TURNO_ACTIVO', ['identity_id' => $identity->id]);
            }

            $registro = ChecadorRegistro::create([
                'user_firebird_identity_id' => $identity->id,
                'firebird_user_id'          => $identity->firebird_user_clave,
                'firebird_tb_clave'         => $identity->firebird_tb_clave,
                'empresa'                   => $identity->firebird_empresa,
                'tipo'                      => $tipo,
                'fecha_hora'                => $ahora,
                'fecha'                     => $hoy,
                'turno_id'                  => $turnoId,
                'turno_nombre'              => $turnoNombre,
                'hora_esperada'             => $horaEsperada,
                'minutos_diferencia'        => $minutosDiferencia,
                'retardo'                   => $retardo,
                'ip'                        => $request->ip(),
                'dispositivo'               => $request->dispositivo,
                'metodo'                    => 'qr',
            ]);

            Log::info('✅ CHECADOR_REGISTRO_OK', [
                'identity_id' => $identity->id,
                'tipo'        => $tipo,
                'retardo'     => $retardo,
            ]);

            return response()->json([
                'message'  => $tipo === 'entrada' ? 'Entrada registrada' : 'Salida registrada',
                'tipo'     => $tipo,
                'nombre'   => $usuario->NOMBRE ? trim((string) $usuario->NOMBRE) : null,
                'photo'    => $usuario->PHOTO ? trim((string) $usuario->PHOTO) : null,
                'empresa'  => $identity->firebird_empresa,
                'turno'    => $turnoNombre,
                'hora'     => $ahora->format('H:i:s'),
                'fecha'    => $hoy,
                'retardo'  => $retardo,
                'minutos_diferencia' => $minutosDiferencia,
                'registro' => $registro,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('💥 Error en scan()', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al registrar checada'], 500);
        }
    }

    /**
     * GET /checador/mi-historial?desde=&hasta=
     * Historial del propio usuario autenticado (usa el JWT normal).
     */
    public function miHistorial(Request $request)
    {
        try {
            $usuario = $request->attributes->get('auth_usuario') ?? $this->resolverUsuarioAutenticado($request);

            if (!$usuario) {
                return response()->json(['message' => 'No autenticado'], 401);
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->ID)->first();

            if (!$identity) {
                return response()->json(['data' => []], 200);
            }

            $query = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                ->orderByDesc('fecha_hora');

            if ($request->filled('desde')) {
                $query->whereDate('fecha', '>=', $request->desde);
            }
            if ($request->filled('hasta')) {
                $query->whereDate('fecha', '<=', $request->hasta);
            }

            return response()->json(['data' => $query->limit(200)->get()], 200);
        } catch (\Throwable $e) {
            Log::error('💥 Error en miHistorial()', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al obtener historial'], 500);
        }
    }

    /**
     * GET /checador/reporte?empresa=&fecha=
     * Reporte administrativo por empresa/día (protegido por rol admin en la ruta).
     */
    public function reporte(Request $request)
    {
        try {
            $fecha = $request->input('fecha', Carbon::now()->toDateString());

            $query = ChecadorRegistro::whereDate('fecha', $fecha)
                ->with('identity')
                ->orderBy('fecha_hora');

            if ($request->filled('empresa')) {
                $query->where('empresa', $request->empresa);
            }

            return response()->json(['data' => $query->get()], 200);
        } catch (\Throwable $e) {
            Log::error('💥 Error en reporte()', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al generar reporte'], 500);
        }
    }

    private function generarTokenUnico(): string
    {
        do {
            $token = Str::random(40);
        } while (UserFirebirdIdentity::where('qr_token', $token)->exists());

        return $token;
    }

    /**
     * Ajusta esto según cómo dejes el usuario autenticado disponible
     * (por ejemplo, si usas un middleware que decodifica el JWT y hace
     * $request->attributes->set('auth_usuario', $usuario), quita este método).
     */
    private function resolverUsuarioAutenticado(Request $request): ?Users
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return null;
        }

        try {
            $decoded = \Firebase\JWT\JWT::decode(
                $bearer,
                new \Firebase\JWT\Key(config('jwt.secret'), 'HS256')
            );

            return Users::find($decoded->sub);
        } catch (\Throwable $e) {
            return null;
        }
    }
}