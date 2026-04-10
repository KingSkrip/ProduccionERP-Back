<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\ModelHasRole;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use App\Services\UserService;
use App\Services\Whatsapp\UltraMSGService;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgendarCitasVisitantesController extends Controller
{
    private string $jwtSecret;
    private UserService $userService;

    public function __construct()
    {
        $this->jwtSecret = config('jwt.secret') ?? env('JWT_SECRET');
        $this->userService = new UserService();
    }

    /* ── Helper: obtener identity del usuario autenticado ── */
    private function getIdentityFromToken(Request $request): ?UserFirebirdIdentity
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        $sub = (int) $decoded->sub;
        if (!$sub) return null;

        return UserFirebirdIdentity::where('firebird_user_clave', $sub)->first();
    }

    /* =========================
     | 📄 ALL USERS
     ========================= */

    public function index(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $citas = Cita::with(['usuario', 'visitante'])
            ->where(function ($query) use ($identity) {
                $query->where('id_user', $identity->id)
                    ->orWhere('id_visitante', $identity->id);
            })
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio', 'asc')
            ->get();

        $citas = $citas->map(function ($cita) use ($identity) {
            $cita->es_externa = $cita->id_visitante === $identity->id
                && $cita->id_user !== $identity->id;

            if ($cita->es_externa) {
                try {
                    $provIdentity = UserFirebirdIdentity::find($cita->id_user);
                    $nombreProveedor = null;

                    if ($provIdentity) {
                        if ($provIdentity->firebird_tb_clave !== null) {
                            $empresaNoi = $provIdentity->firebird_empresa ?? '04';
                            $tbClave = trim((string) $provIdentity->firebird_tb_clave);
                            $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');
                            $tbRow = $firebirdNoi->getOperationalTable('TB')
                                ->keyBy(fn($row) => trim((string) $row->CLAVE))
                                ->get($tbClave);
                            $nombreProveedor = $tbRow->NOMBRE ?? null;
                        } elseif (!empty($provIdentity->firebird_clie_clave)) {
                            $conn = $this->getFirebirdProductionConnection();
                            $row = $conn->selectOne("SELECT NOMBRE FROM CLIE03 WHERE CLAVE = ?", [$provIdentity->firebird_clie_clave]);
                            $nombreProveedor = $row?->NOMBRE ?? null;
                        } elseif ($provIdentity->firebird_vend_clave !== null) {
                            $conn = $this->getFirebirdProductionConnection();
                            $row = $conn->selectOne("SELECT NOMBRE FROM VEND03 WHERE CVE_VEND = ?", [$provIdentity->firebird_vend_clave]);
                            $nombreProveedor = $row?->NOMBRE ?? null;
                        } elseif ($provIdentity->firebird_prov_clave !== null) {
                            $conn = $this->getFirebirdProductionConnection();
                            Log::info('🔍 BUSCANDO_PROV03', [
                                'prov_clave' => $provIdentity->firebird_prov_clave,
                                'type'       => gettype($provIdentity->firebird_prov_clave),
                            ]);
                            $row = $conn->selectOne("SELECT NOMBRE FROM PROV03 WHERE TRIM(CLAVE) = ?", [trim((string) $provIdentity->firebird_prov_clave)]);
                            Log::info('📦 PROV03_RESULT', ['row' => $row]);
                            $nombreProveedor = $row?->NOMBRE ?? null;
                        }
                    }

                    $cita->nombre_proveedor = $nombreProveedor;
                } catch (\Throwable $e) {
                    Log::error('❌ NOMBRE_PROVEEDOR_ERROR', ['error' => $e->getMessage()]);
                    $cita->nombre_proveedor = null;
                }
            }

            return $cita;
        });

        return response()->json($citas);
    }
    /* =========================
     | 💾 CREAR CITA
     ========================= */
    public function store(Request $request){
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'fecha'         => 'required|date',
            'hora_inicio'   => 'required',
            'hora_fin'      => 'required|after:hora_inicio',
            'visitantes'    => 'required|array|min:1',
            'visitantes.*'  => 'required|integer',
            'motivo'        => 'nullable|string|max:255',
            'estado'        => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'         => 'nullable|string',
            'con_vehiculo'  => 'nullable|boolean',
        ], [
            'visitantes.required' => 'Debes seleccionar al menos un proveedor a invitar.',
            'visitantes.min'      => 'Debes seleccionar al menos un proveedor a invitar.',
            'hora_fin.after'      => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'      => 'La fecha es obligatoria.',
        ]);

        // ── Yo soy el anfitrión (id_user) ──
        $idAnfitrion = $identity->id;
        $citasCreadas = [];
        $errores = [];

        // ── Verificar cruce en MI agenda ──
        $cruceAnfitrion = Cita::where('id_user', $idAnfitrion)
            ->where('fecha', $request->fecha)
            ->where('hora_inicio', '<', $request->hora_fin)
            ->where('hora_fin', '>', $request->hora_inicio)
            ->exists();

        if ($cruceAnfitrion) {
            return response()->json([
                'message' => 'Ya tienes una cita agendada en ese horario.',
                'errores' => [],
            ], 422);
        }

        // ── Obtener mi nombre y teléfono ──
        $meData = $this->userService->me($request);
        $telefonoAnfitrion = $this->obtenerTelefonoUsuario($meData['user']);
        $nombreAnfitrion = $meData['user']['TB']->NOMBRE
            ?? $meData['user']['CLIE']->NOMBRE
            ?? $meData['user']['VEND']->NOMBRE
            ?? $meData['user']['PROV']?->NOMBRE
            ?? 'Un colaborador';

        foreach ($request->visitantes as $idProveedor) {
            $proveedorIdentity = UserFirebirdIdentity::find($idProveedor);

            if (!$proveedorIdentity) {
                $errores[] = "Proveedor con id {$idProveedor} no encontrado.";
                continue;
            }

            // ── Verificar cruce en la agenda del PROVEEDOR ──
            $cruce = Cita::where('id_user', $proveedorIdentity->id)
                ->where('fecha', $request->fecha)
                ->where('hora_inicio', '<', $request->hora_fin)
                ->where('hora_fin', '>', $request->hora_inicio)
                ->first();

            if ($cruce) {
                $nombreProv = $proveedorIdentity->firebirdUser->NOMBRE ?? "ID {$idProveedor}";
                $horaIniCruce = Carbon::parse($cruce->hora_inicio)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
                $horaFinCruce = Carbon::parse($cruce->hora_fin)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');
                $errores[] = "{$nombreProv} ya tiene una cita de {$horaIniCruce} a {$horaFinCruce}.";
                continue;
            }

            // ── Crear la cita:
            //    id_user      = YO (anfitrión interno)
            //    id_visitante = el proveedor que viene
            // ──
            $nombreProv = $proveedorIdentity->firebirdUser->NOMBRE ?? null;

            $cita = Cita::create([
                'id_user'          => $idAnfitrion,
                'id_visitante'     => $proveedorIdentity->id,
                'nombre_visitante' => $nombreProv,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->motivo,
                'estado'           => $request->estado ?? 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => $request->con_vehiculo ?? false,
                'created_at'       => now(),
            ]);

            $citasCreadas[] = $cita;

            $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
            $horaIni = Carbon::parse($cita->hora_inicio)->format('g:i') . ' ' .
                (Carbon::parse($cita->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
            $horaFin = Carbon::parse($cita->hora_fin)->format('g:i') . ' ' .
                (Carbon::parse($cita->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');
            $vehiculoTexto = $request->con_vehiculo ? "\n🚗 Asistirá en vehículo." : '';

            // ── WhatsApp a MÍ (anfitrión) ──
            try {
                if ($telefonoAnfitrion) {
                    $mensajeAnfitrion = "✅ Has agendado una cita con *{$nombreProv}* para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . $vehiculoTexto;
                    $this->enviarMensajeAlUsuario($request, $mensajeAnfitrion, $telefonoAnfitrion);
                }
            } catch (\Throwable $e) {
                Log::error('❌ INVITAR_PROVEEDOR_WHATSAPP_ANFITRION_ERROR', [
                    'error'   => $e->getMessage(),
                    'cita_id' => $cita->id,
                ]);
            }

            // ── WhatsApp al PROVEEDOR invitado ──
            // try {
            //     $telefonoProveedor = $this->obtenerTelefonoDeIdentity($proveedorIdentity);
            //     if ($telefonoProveedor) {
            //         $mensajeProveedor = "Hola, *{$nombreAnfitrion}* te ha invitado a una cita para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
            //             . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
            //             . $vehiculoTexto
            //             . ($request->con_vehiculo ? "\n\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil." : '');
            //         $this->enviarMensajeAlUsuario($request, $mensajeProveedor, $telefonoProveedor);
            //     }
            // } catch (\Throwable $e) {
            //     Log::error('❌ INVITAR_PROVEEDOR_WHATSAPP_PROVEEDOR_ERROR', [
            //         'error'        => $e->getMessage(),
            //         'id_proveedor' => $idProveedor,
            //         'cita_id'      => $cita->id,
            //     ]);
            // }
        }

        if (empty($citasCreadas)) {
            return response()->json([
                'message' => 'No se pudo crear ninguna cita.',
                'errores' => $errores,
            ], 422);
        }

        return response()->json([
            'message' => count($citasCreadas) . ' cita(s) registrada(s) con éxito.',
            'citas'   => $citasCreadas,
            'errores' => $errores,
        ], 201);
    }

    /**
     * 📲 Enviar mensaje de WhatsApp al usuario logueado (debug completo)
     */
    public function enviarMensajeAlUsuario(Request $request, string $mensaje, string $telefono)
    {
        try {
            $whatsapp = new UltraMSGService();
            $resultado = $whatsapp->sendMessage($telefono, $mensaje);
            Log::info("✅ WhatsApp enviado", ['telefono' => $telefono, 'resultado' => $resultado]);
        } catch (\Throwable $e) {
            Log::error("❌ Error enviando WhatsApp", ['error' => $e->getMessage()]);
        }
    }

    /* =========================
     | 🔍 VER UNA CITA
     ========================= */
    public function show(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $cita = Cita::with(['usuario', 'visitante'])
            ->where('id_user', $identity->id)
            ->find($id);

        if (!$cita) {
            return response()->json(['message' => 'Cita no encontrada'], 404);
        }

        return response()->json([
            'id'               => $cita->id,
            'fecha'            => $cita->fecha,
            'hora_inicio'      => $cita->hora_inicio,
            'hora_fin'         => $cita->hora_fin,
            'nombre_visitante' => $cita->nombre_visitante,
            'motivo'           => $cita->motivo,
            'estado'           => $cita->estado,
            'notas'            => $cita->notas,
            'created_at'       => $cita->created_at,
            'usuario'          => $cita->usuario?->only(['id', 'name', 'email']) ?? null,
            'visitante'        => $cita->visitante ?? null,
        ]);
    }

    /* =========================
    | ✏️ ACTUALIZAR CITA
    ========================= */
public function update(Request $request, $id)
{
    $identity = $this->getIdentityFromToken($request);
    if (!$identity) {
        return response()->json(['message' => 'No autenticado'], 401);
    }

    $cita = Cita::where('id_user', $identity->id)->find($id);
    if (!$cita) {
        return response()->json(['message' => 'Cita no encontrada'], 404);
    }

    $request->validate([
        'fecha'         => 'sometimes|required|date',
        'hora_inicio'   => 'sometimes|required',
        'hora_fin'      => 'sometimes|required|after:hora_inicio',
        'visitantes'    => 'sometimes|required|array|min:1',
        'visitantes.*'  => 'required|integer',
        'motivo'        => 'nullable|string|max:255',
        'estado'        => 'nullable|in:pendiente,confirmada,cancelada',
        'notas'         => 'nullable|string',
        'con_vehiculo'  => 'nullable|boolean',
    ], [
        'visitantes.required' => 'Debes seleccionar al menos un proveedor a invitar.',
        'visitantes.min'      => 'Debes seleccionar al menos un proveedor a invitar.',
        'hora_fin.after'      => 'La hora de fin debe ser mayor que la hora de inicio.',
        'fecha.required'      => 'La fecha es obligatoria.',
    ]);

    $idAnfitrion = $identity->id;

    $fecha       = $request->fecha       ?? $cita->fecha;
    $hora_inicio = $request->hora_inicio ?? $cita->hora_inicio;
    $hora_fin    = $request->hora_fin    ?? $cita->hora_fin;

    // ── Verificar cruce en MI agenda (excluyendo la cita actual) ──
    $cruceAnfitrion = Cita::where('id_user', $idAnfitrion)
        ->where('fecha', $fecha)
        ->where('id', '!=', $id)
        ->where('hora_inicio', '<', $hora_fin)
        ->where('hora_fin', '>', $hora_inicio)
        ->exists();

    if ($cruceAnfitrion) {
        return response()->json([
            'message' => 'Ya tienes una cita agendada en ese horario.',
            'errores' => [],
        ], 422);
    }

    // ── Obtener mi nombre y teléfono ──
    $meData            = $this->userService->me($request);
    $telefonoAnfitrion = $this->obtenerTelefonoUsuario($meData['user']);
    $nombreAnfitrion   = $meData['user']['TB']->NOMBRE
        ?? $meData['user']['CLIE']->NOMBRE
        ?? $meData['user']['VEND']->NOMBRE
        ?? $meData['user']['PROV']?->NOMBRE
        ?? 'Un colaborador';

    // ── Si vienen visitantes nuevos, actualizar la cita con el primer visitante
    //    y verificar cruces en su agenda ──
    if ($request->has('visitantes')) {

        $citasActualizadas = [];
        $errores           = [];

        foreach ($request->visitantes as $idProveedor) {
            $proveedorIdentity = UserFirebirdIdentity::find($idProveedor);

            if (!$proveedorIdentity) {
                $errores[] = "Proveedor con id {$idProveedor} no encontrado.";
                continue;
            }

            // ── Verificar cruce en agenda del PROVEEDOR (excluyendo la cita actual) ──
            $cruce = Cita::where('id_visitante', $proveedorIdentity->id)
                ->where('fecha', $fecha)
                ->where('id', '!=', $id)
                ->where('hora_inicio', '<', $hora_fin)
                ->where('hora_fin', '>', $hora_inicio)
                ->first();

            if ($cruce) {
                $nombreProv     = $proveedorIdentity->firebirdUser->NOMBRE ?? "ID {$idProveedor}";
                $horaIniCruce   = Carbon::parse($cruce->hora_inicio)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
                $horaFinCruce   = Carbon::parse($cruce->hora_fin)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');
                $errores[]      = "{$nombreProv} ya tiene una cita de {$horaIniCruce} a {$horaFinCruce}.";
                continue;
            }

            $nombreProv = $proveedorIdentity->firebirdUser->NOMBRE ?? null;

            // Si es el mismo visitante de la cita original, actualizamos esa misma fila
            // Si es un visitante distinto (se cambió el visitante), también actualizamos
            $cita->update([
                'id_visitante'     => $proveedorIdentity->id,
                'nombre_visitante' => $nombreProv,
                'fecha'            => $fecha,
                'hora_inicio'      => $hora_inicio,
                'hora_fin'         => $hora_fin,
                'motivo'           => $request->motivo        ?? $cita->motivo,
                'estado'           => $request->estado        ?? $cita->estado,
                'notas'            => $request->notas         ?? $cita->notas,
                'con_vehiculo'     => $request->con_vehiculo  ?? $cita->con_vehiculo,
            ]);

            $citasActualizadas[] = $cita->fresh();

            $fechaFmt    = Carbon::parse($fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
            $horaIniFmt  = Carbon::parse($hora_inicio)->format('g:i') . ' ' .
                (Carbon::parse($hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
            $horaFinFmt  = Carbon::parse($hora_fin)->format('g:i') . ' ' .
                (Carbon::parse($hora_fin)->format('A') === 'AM' ? 'am' : 'pm');
            $vehiculoTexto = ($request->con_vehiculo ?? $cita->con_vehiculo)
                ? "\n🚗 Asistirá en vehículo." : '';

            // ── WhatsApp a MÍ (anfitrión) ──
            try {
                if ($telefonoAnfitrion) {
                    $mensajeAnfitrion = "✏️ Has actualizado tu cita con *{$nombreProv}* para el día *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . $vehiculoTexto;
                    $this->enviarMensajeAlUsuario($request, $mensajeAnfitrion, $telefonoAnfitrion);
                }
            } catch (\Throwable $e) {
                Log::error('❌ UPDATE_WHATSAPP_ANFITRION_ERROR', [
                    'error'   => $e->getMessage(),
                    'cita_id' => $cita->id,
                ]);
            }
        }

        if (empty($citasActualizadas)) {
            return response()->json([
                'message' => 'No se pudo actualizar ninguna cita.',
                'errores' => $errores,
            ], 422);
        }

        return response()->json([
            'message' => count($citasActualizadas) . ' cita(s) actualizada(s) con éxito.',
            'citas'   => $citasActualizadas,
            'errores' => $errores,
        ]);
    }

    // ── Sin visitantes nuevos: solo actualiza campos simples ──
    $cita->update([
        'fecha'            => $fecha,
        'hora_inicio'      => $hora_inicio,
        'hora_fin'         => $hora_fin,
        'motivo'           => $request->motivo       ?? $cita->motivo,
        'estado'           => $request->estado       ?? $cita->estado,
        'notas'            => $request->notas        ?? $cita->notas,
        'con_vehiculo'     => $request->con_vehiculo ?? $cita->con_vehiculo,
    ]);

    return response()->json([
        'message' => 'Cita actualizada con éxito.',
        'cita'    => $cita->fresh(),
    ]);
}

    /* =========================
     | 🗑️ ELIMINAR CITA
     ========================= */
    public function destroy(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $cita = Cita::where('id_user', $identity->id)->find($id);
        if (!$cita) {
            return response()->json(['message' => 'Cita no encontrada'], 404);
        }

        $cita->delete();

        return response()->json(['message' => 'Cita eliminada correctamente'], 200);
    }

    public function UsuariosPermitidosParaAllUsers()
    {
        $combinaciones = [
            ['role_id' => 8, 'subrol_id' => null],
        ];

        $identities = ModelHasRole::with([
            'firebirdIdentity.firebirdUser', // 🔥 trae usuario real
            'role',
            'subrol'
        ])
            ->where(function ($q) use ($combinaciones) {
                foreach ($combinaciones as $combo) {
                    $q->orWhere(function ($subQ) use ($combo) {
                        $subQ->where('role_id', $combo['role_id']);

                        if (is_null($combo['subrol_id'])) {
                            $subQ->whereNull('subrol_id');
                        } else {
                            $subQ->where('subrol_id', $combo['subrol_id']);
                        }
                    });
                }
            })
            ->get();

        $resultado = $identities->map(function ($item) {

            $identity = $item->firebirdIdentity;
            $user = $identity?->firebirdUser;

            return [
                'id' => $identity?->id,
                'user_id' => $user?->ID,
                'nombre' => $user?->NOMBRE ?? 'Sin nombre',
                'correo' => $user?->CORREO ?? null,

                'rol' => [
                    'id' => $item->role?->id,
                    'nombre' => $item->role?->nombre,
                ],

                'subrol' => [
                    'id' => $item->subrol?->id,
                    'nombre' => $item->subrol?->nombre,
                ],
            ];
        });

        return response()->json($resultado);
    }




    /* =========================
    | 👻HEALPERS
    ========================= */
    private function obtenerTelefonoUsuario(array $userData): ?string
    {
        $tipoUsuario = $userData['tipo_usuario'] ?? null;

        // =====================================================
        // 🏢 EMPLEADO: Teléfono desde TB (datos NOI)
        // =====================================================
        if ($tipoUsuario === 'empleado') {
            $tb = $userData['TB'] ?? null;

            if ($tb) {
                // Intentar campos comunes de teléfono en tabla TB (NOI)
                return $tb->TELEFONO
                    ?? $tb->TEL
                    ?? $tb->TEL_CELULAR
                    ?? $tb->CELULAR
                    ?? $tb->TEL_PARTICULAR
                    ?? null;
            }

            return null;
        }

        // =====================================================
        // 🛒 CLIENTE: Teléfono desde CLIE03
        // =====================================================
        if ($tipoUsuario === 'cliente') {
            $clie = $userData['CLIE'] ?? null;

            if ($clie) {
                return $clie->TELEFONO
                    ?? $clie->TEL
                    ?? $clie->CELULAR
                    ?? $clie->TEL_CELULAR
                    ?? null;
            }

            return null;
        }

        // =====================================================
        // 🧑‍💼 VENDEDOR: Teléfono desde VEND03
        // =====================================================
        if ($tipoUsuario === 'vendedor') {
            $vend = $userData['VEND'] ?? null;

            if ($vend) {
                return $vend->TELEFONO
                    ?? $vend->TEL
                    ?? $vend->CELULAR
                    ?? $vend->TEL_CELULAR
                    ?? null;
            }

            return null;
        }

        return null;
    }









































    /* =========================
    | 💾PROVEDORES
    ========================= */


    public function updateProveedor(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'ids'           => 'required|array|min:1',
            'ids.*'         => 'required|integer',
            'fecha'         => 'required|date',
            'hora_inicio'   => 'required',
            'hora_fin'      => 'required|after:hora_inicio',
            'visitantes'    => 'required|array|min:1',
            'visitantes.*'  => 'required|integer',
            'motivo'        => 'nullable|string|max:255',
            'estado'        => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'         => 'nullable|string',
            'con_vehiculo'  => 'nullable|boolean',
        ], [
            'ids.required'           => 'Se requieren los ids de las citas a actualizar.',
            'visitantes.required'    => 'Debes seleccionar al menos un usuario a visitar.',
            'visitantes.min'         => 'Debes seleccionar al menos un usuario a visitar.',
            'hora_fin.after'         => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'         => 'La fecha es obligatoria.',
        ]);

        $idProveedor = $identity->id;

        // ── Eliminar las citas viejas del grupo (solo las del proveedor autenticado) ──
        Cita::whereIn('id', $request->ids)
            ->where('id_user', $idProveedor)
            ->delete();

        // ── Obtener datos del proveedor para WhatsApp ──
        $meData        = $this->userService->me($request);
        $nombreProveedor = $meData['user']['TB']->NOMBRE
            ?? $meData['user']['CLIE']->NOMBRE
            ?? $meData['user']['VEND']->NOMBRE
            ?? $meData['user']['PROV']?->NOMBRE
            ?? 'Un proveedor';

        $citasCreadas = [];
        $errores      = [];

        foreach ($request->visitantes as $idVisitante) {

            $visitanteIdentity = UserFirebirdIdentity::find($idVisitante);

            if (!$visitanteIdentity) {
                $errores[] = "Visitante con id {$idVisitante} no encontrado.";
                continue;
            }

            // ── Verificar cruce en el horario del VISITANTE ──
            // (las citas viejas ya fueron eliminadas, no hay riesgo de auto-bloqueo)
            $cruce = Cita::where('id_user', $visitanteIdentity->id)
                ->where('fecha', $request->fecha)
                ->where('hora_inicio', '<', $request->hora_fin)
                ->where('hora_fin', '>', $request->hora_inicio)
                ->first();

            if ($cruce) {
                $nombreVisitante = $visitanteIdentity->firebirdUser->NOMBRE ?? "ID {$idVisitante}";

                $horaIniCruce = Carbon::parse($cruce->hora_inicio)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
                $horaFinCruce = Carbon::parse($cruce->hora_fin)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');

                $errores[] = "{$nombreVisitante} ya tiene una cita de {$horaIniCruce} a {$horaFinCruce}.";
                continue;
            }

            // ── Recrear la cita ──
            $cita = Cita::create([
                'id_user'          => $idProveedor,
                'id_visitante'     => $visitanteIdentity->id,
                'nombre_visitante' => $visitanteIdentity->firebirdUser->NOMBRE ?? null,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->motivo,
                'estado'           => $request->estado ?? 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => $request->con_vehiculo ?? false,
                'created_at'       => now(),
            ]);

            $citasCreadas[] = $cita;

            // ── Enviar WhatsApp al VISITANTE ──
            try {
                $telefonoVisitante = $this->obtenerTelefonoDeIdentity($visitanteIdentity);

                if ($telefonoVisitante) {
                    $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                    $horaIni = Carbon::parse($cita->hora_inicio)->format('g:i') . ' ' .
                        (Carbon::parse($cita->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
                    $horaFin = Carbon::parse($cita->hora_fin)->format('g:i') . ' ' .
                        (Carbon::parse($cita->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');

                    $vehiculoTexto = $request->con_vehiculo ? "\n🚗 Viene en vehículo." : '';

                    $mensaje = "Hola, *{$nombreProveedor}* ha actualizado su cita contigo para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . $vehiculoTexto
                        . "\n\n⚠️ Recuerda pedir autorización de dirección para el ingreso con automóvil";

                    $this->enviarMensajeAlUsuario($request, $mensaje, $telefonoVisitante);
                }
            } catch (\Throwable $e) {
                Log::error('❌ UPDATE_PROVEEDOR_WHATSAPP_ERROR', [
                    'error'        => $e->getMessage(),
                    'id_visitante' => $idVisitante,
                    'cita_id'      => $cita->id,
                ]);
            }
        }

        if (empty($citasCreadas)) {
            return response()->json([
                'message' => 'No se pudo actualizar ninguna cita.',
                'errores' => $errores,
            ], 422);
        }

        return response()->json([
            'message' => count($citasCreadas) . ' cita(s) actualizada(s) con éxito.',
            'citas'   => $citasCreadas,
            'errores' => $errores,
        ]);
    }

    public function destroyProveedor(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        $eliminadas = Cita::whereIn('id', $request->ids)
            ->where('id_user', $identity->id)
            ->delete();

        return response()->json([
            'message'   => "{$eliminadas} cita(s) eliminada(s).",
        ]);
    }

    public function UsuariosPermitidosParaProvedores()
    {
        $combinaciones = [
            ['role_id' => 3, 'subrol_id' => 7],
            ['role_id' => 3, 'subrol_id' => 9],
            ['role_id' => 3, 'subrol_id' => 10],

            ['role_id' => 1, 'subrol_id' => 12],
            ['role_id' => 1, 'subrol_id' => 7],
            ['role_id' => 1, 'subrol_id' => 3],
            ['role_id' => 1, 'subrol_id' => 6],
            ['role_id' => 1, 'subrol_id' => 14],
            ['role_id' => 1, 'subrol_id' => 13],



            ['role_id' => 3, 'subrol_id' => null],
        ];

        $identities = ModelHasRole::with([
            'firebirdIdentity.firebirdUser', // 🔥 trae usuario real
            'role',
            'subrol'
        ])
            ->where(function ($q) use ($combinaciones) {
                foreach ($combinaciones as $combo) {
                    $q->orWhere(function ($subQ) use ($combo) {
                        $subQ->where('role_id', $combo['role_id']);

                        if (is_null($combo['subrol_id'])) {
                            $subQ->whereNull('subrol_id');
                        } else {
                            $subQ->where('subrol_id', $combo['subrol_id']);
                        }
                    });
                }
            })
            ->get();

        $resultado = $identities->map(function ($item) {

            $identity = $item->firebirdIdentity;
            $user = $identity?->firebirdUser;

            return [
                'id' => $identity?->id,
                'user_id' => $user?->ID,
                'nombre' => $user?->NOMBRE ?? 'Sin nombre',
                'correo' => $user?->CORREO ?? null,

                'rol' => [
                    'id' => $item->role?->id,
                    'nombre' => $item->role?->nombre,
                ],

                'subrol' => [
                    'id' => $item->subrol?->id,
                    'nombre' => $item->subrol?->nombre,
                ],
            ];
        });

        return response()->json($resultado);
    }


    public function storeProveedor(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'fecha'         => 'required|date',
            'hora_inicio'   => 'required',
            'hora_fin'      => 'required|after:hora_inicio',
            'visitantes'    => 'required|array|min:1',
            'visitantes.*'  => 'required|integer',
            'motivo'        => 'nullable|string|max:255',
            'estado'        => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'         => 'nullable|string',
            'con_vehiculo'  => 'nullable|boolean',
        ], [
            'visitantes.required'    => 'Debes seleccionar al menos un usuario a visitar.',
            'visitantes.min'         => 'Debes seleccionar al menos un usuario a visitar.',
            'hora_fin.after'         => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'         => 'La fecha es obligatoria.',
        ]);

        $idProveedor = $identity->id;
        $citasCreadas = [];
        $errores = [];

        // ── Obtener nombre directo desde Firebird usando identity ──
        $nombreProveedor = 'Sin nombre';
        try {
            if ($identity->firebird_tb_clave !== null) {
                $empresaNoi = $identity->firebird_empresa ?? '04';
                $tbClave = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');
                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))->get($tbClave);
                $nombreProveedor = $tbRow->NOMBRE ?? 'Sin nombre';
            } elseif (!empty($identity->firebird_clie_clave)) {
                $conn = $this->getFirebirdProductionConnection();
                $row = $conn->selectOne("SELECT NOMBRE FROM CLIE03 WHERE CLAVE = ?", [$identity->firebird_clie_clave]);
                $nombreProveedor = $row?->NOMBRE ?? 'Sin nombre';
            } elseif ($identity->firebird_vend_clave !== null) {
                $conn = $this->getFirebirdProductionConnection();
                $row = $conn->selectOne("SELECT NOMBRE FROM VEND03 WHERE CVE_VEND = ?", [$identity->firebird_vend_clave]);
                $nombreProveedor = $row?->NOMBRE ?? 'Sin nombre';
            } elseif ($identity->firebird_prov_clave !== null) {
                $conn = $this->getFirebirdProductionConnection();
                $row = $conn->selectOne("SELECT NOMBRE FROM PROV03 WHERE TRIM(CLAVE) = ?", [trim((string) $identity->firebird_prov_clave)]);
                $nombreProveedor = $row?->NOMBRE ?? 'Sin nombre';
            }
        } catch (\Throwable $e) {
            Log::error('❌ NOMBRE_PROVEEDOR_STORE_ERROR', ['error' => $e->getMessage()]);
        }

        $meData = $this->userService->me($request);
        $telefonoProveedor = $this->obtenerTelefonoUsuario($meData['user']);

        $cruceProveedor = Cita::where('id_user', $idProveedor)
            ->where('fecha', $request->fecha)
            ->where('hora_inicio', '<', $request->hora_fin)
            ->where('hora_fin', '>', $request->hora_inicio)
            ->exists();

        if ($cruceProveedor) {
            return response()->json([
                'message' => 'Ya tienes una cita agendada en ese horario.',
                'errores' => [],
            ], 422);
        }
        foreach ($request->visitantes as $idVisitante) {
            $visitanteIdentity = UserFirebirdIdentity::find($idVisitante);

            if (!$visitanteIdentity) {
                Log::warning('❌ VISITANTE_IDENTITY_NO_ENCONTRADO', ['id_visitante' => $idVisitante]);
                $errores[] = "Visitante con id {$idVisitante} no encontrado.";
                continue;
            }

            $cruce = Cita::where('id_user', $visitanteIdentity->id)
                ->where('fecha', $request->fecha)
                ->where('hora_inicio', '<', $request->hora_fin)
                ->where('hora_fin', '>', $request->hora_inicio)
                ->first();

            if ($cruce) {
                $nombreVisitante = $visitanteIdentity->firebirdUser->NOMBRE ?? "ID {$idVisitante}";
                $horaIniCruce = Carbon::parse($cruce->hora_inicio)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
                $horaFinCruce = Carbon::parse($cruce->hora_fin)->format('g:i') . ' ' .
                    (Carbon::parse($cruce->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');
                $errores[] = "{$nombreVisitante} ya tiene una cita de {$horaIniCruce} a {$horaFinCruce}.";
                continue;
            }


            // ── Verificar que el PROVEEDOR no tenga ya una cita en ese horario ──


            $cita = Cita::create([
                'id_user'          => $idProveedor,
                'id_visitante'     => $visitanteIdentity->id,
                'nombre_visitante' => $visitanteIdentity->firebirdUser->NOMBRE ?? null,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->motivo,
                'estado'           => $request->estado ?? 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => $request->con_vehiculo ?? false,
                'created_at'       => now(),
            ]);

            $citasCreadas[] = $cita;

            $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
            $horaIni = Carbon::parse($cita->hora_inicio)->format('g:i') . ' ' .
                (Carbon::parse($cita->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
            $horaFin = Carbon::parse($cita->hora_fin)->format('g:i') . ' ' .
                (Carbon::parse($cita->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');
            $vehiculoTexto = $request->con_vehiculo ? "\n🚗 Viene en vehículo." : '';
            $nombreVisitante = $visitanteIdentity->firebirdUser->NOMBRE ?? "ID {$idVisitante}";



            // ── WhatsApp al PROVEEDOR ──
            try {
                if ($telefonoProveedor) {
                    $mensajeProveedor = "✅ Tu cita con *{$nombreVisitante}* ha sido registrada para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . ($request->con_vehiculo ? "\n🚗 Asistirás con vehículo." : '');
                    $this->enviarMensajeAlUsuario($request, $mensajeProveedor, $telefonoProveedor);
                }
            } catch (\Throwable $e) {
                Log::error('❌ CITA_PROVEEDOR_WHATSAPP_PROVEEDOR_ERROR', [
                    'error'   => $e->getMessage(),
                    'cita_id' => $cita->id,
                ]);
            }

            // ── WhatsApp al VISITANTE ──
            try {
                $telefonoVisitante = $this->obtenerTelefonoDeIdentity($visitanteIdentity);
                if ($telefonoVisitante) {
                    $mensajeVisitante = "Hola, *{$nombreProveedor}* ha agendado una cita contigo para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . $vehiculoTexto
                        . "\n\n⚠️ Recuerda pedir autorización de dirección para el ingreso con automóvil";
                    $this->enviarMensajeAlUsuario($request, $mensajeVisitante, $telefonoVisitante);
                } else {
                    Log::warning('⚠️ VISITANTE_SIN_TELEFONO', ['identity_id' => $visitanteIdentity->id]);
                }
            } catch (\Throwable $e) {
                Log::error('❌ CITA_PROVEEDOR_WHATSAPP_VISITANTE_ERROR', [
                    'error'        => $e->getMessage(),
                    'id_visitante' => $idVisitante,
                    'cita_id'      => $cita->id,
                ]);
            }
        }

        if (empty($citasCreadas)) {
            return response()->json([
                'message' => 'No se pudo crear ninguna cita.',
                'errores' => $errores,
            ], 422);
        }

        return response()->json([
            'message' => count($citasCreadas) . ' cita(s) registrada(s) con éxito.',
            'citas'   => $citasCreadas,
            'errores' => $errores,
        ], 201);
    }

    /* ── Helper: obtener teléfono directo de una identity ── */
    private function obtenerTelefonoDeIdentity(UserFirebirdIdentity $identity): ?string
    {
        // =====================================================
        // 🏢 EMPLEADO: teléfono desde TB (NOI)
        // =====================================================
        if ($identity->firebird_tb_clave !== null) {
            try {
                $empresaNoi = $identity->firebird_empresa ?? '04';
                $tbClave    = trim((string) $identity->firebird_tb_clave);

                $firebirdNoi = new \App\Services\FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    return $tbRow->TELEFONO
                        ?? $tbRow->TEL
                        ?? $tbRow->TEL_CELULAR
                        ?? $tbRow->CELULAR
                        ?? $tbRow->TEL_PARTICULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_EMPLEADO_ERROR', [
                    'identity_id' => $identity->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            return null;
        }

        // =====================================================
        // 🛒 CLIENTE: teléfono desde CLIE03
        // =====================================================
        if ($identity->firebird_clie_clave !== null) {
            try {
                $connection = $this->getFirebirdProductionConnection();
                $clieRow = $connection->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM CLIE03 WHERE CLAVE = ?",
                    [$identity->firebird_clie_clave]
                );

                if ($clieRow) {
                    return $clieRow->TELEFONO
                        ?? $clieRow->TEL
                        ?? $clieRow->CELULAR
                        ?? $clieRow->TEL_CELULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_CLIENTE_ERROR', [
                    'identity_id' => $identity->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            return null;
        }

        // =====================================================
        // 🧑‍💼 VENDEDOR: teléfono desde VEND03
        // =====================================================
        if ($identity->firebird_vend_clave !== null) {
            try {
                $connection = $this->getFirebirdProductionConnection();
                $vendRow = $connection->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM VEND03 WHERE CVE_VEND = ?",
                    [$identity->firebird_vend_clave]
                );

                if ($vendRow) {
                    return $vendRow->TELEFONO
                        ?? $vendRow->TEL
                        ?? $vendRow->CELULAR
                        ?? $vendRow->TEL_CELULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_VENDEDOR_ERROR', [
                    'identity_id' => $identity->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            return null;
        }

        // =====================================================
        // 📦 PROVEEDOR: teléfono desde PROV03
        // =====================================================
        if ($identity->firebird_prov_clave !== null) {
            try {
                $connection = $this->getFirebirdProductionConnection();
                $provRow = $connection->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM PROV03 WHERE CLAVE = ?",
                    [$identity->firebird_prov_clave]
                );

                if ($provRow) {
                    return $provRow->TELEFONO
                        ?? $provRow->TEL
                        ?? $provRow->CELULAR
                        ?? $provRow->TEL_CELULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_PROVEEDOR_ERROR', [
                    'identity_id' => $identity->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            return null;
        }

        return null;
    }


    private function getFirebirdProductionConnection(): \Illuminate\Database\Connection
    {
        config([
            'database.connections.firebird_produccion' => [
                'driver'            => 'firebird',
                'host'              => env('FB_HOST'),
                'port'              => env('FB_PORT'),
                'database'          => env('FB_DATABASE'),
                'username'          => env('FB_USERNAME'),
                'password'          => env('FB_PASSWORD'),
                'charset'           => env('FB_CHARSET', 'UTF8'),
                'dialect'           => 3,
                'quote_identifiers' => false,
            ]
        ]);

        DB::purge('firebird_produccion');

        return DB::connection('firebird_produccion');
    }
}