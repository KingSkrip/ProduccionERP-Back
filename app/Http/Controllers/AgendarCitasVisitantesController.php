<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Services\UserService;
use App\Services\Whatsapp\UltraMSGService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     | 📄 LISTAR CITAS
     ========================= */
    public function index(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $citas = Cita::with(['usuario', 'visitante'])
            ->where('id_user', $identity->id)
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio', 'asc')
            ->get();

        return response()->json($citas);
    }

    /* =========================
     | 💾 CREAR CITA
     ========================= */
    public function store(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'fecha'            => 'required|date',
            'hora_inicio'      => 'required',
            'hora_fin'         => 'required|after:hora_inicio',
            'nombre_visitante' => 'nullable|string|max:255',
            'motivo'           => 'nullable|string|max:255',
            'estado'           => 'in:pendiente,confirmada,cancelada',
            'notas'            => 'nullable|string',
        ], [
            'hora_fin.after'        => 'La hora de fin debe ser mayor que la hora de inicio.',
            'hora_fin.required'     => 'La hora de fin es obligatoria.',
            'hora_inicio.required'  => 'La hora de inicio es obligatoria.',
            'fecha.required'        => 'La fecha es obligatoria.',
            'fecha.date'            => 'La fecha no es válida.',
        ]);

        $idUser = $identity->id;

        $existeCruce = Cita::where('id_user', $idUser)
            ->where('fecha', $request->fecha)
            ->where(function ($q) use ($request) {
                $q->whereBetween('hora_inicio', [$request->hora_inicio, $request->hora_fin])
                    ->orWhereBetween('hora_fin', [$request->hora_inicio, $request->hora_fin])
                    ->orWhere(
                        fn($q2) => $q2
                            ->where('hora_inicio', '<=', $request->hora_inicio)
                            ->where('hora_fin', '>=', $request->hora_fin)
                    );
            })->exists();

        if ($existeCruce) {
            return response()->json(['message' => 'Ya existe una cita en ese horario'], 422);
        }

        $cita = Cita::create([
            ...$request->only(['fecha', 'hora_inicio', 'hora_fin', 'nombre_visitante', 'motivo', 'estado', 'notas']),
            'id_user'      => $idUser,
            'id_visitante' => null,
            'created_at'   => now(),
        ]);

        // ── Declarar fuera del try para que esté disponible en $response ──
        $whatsappEnviado = false;

        try {
            $meData = $this->userService->me($request);

            if (!$meData || !isset($meData['user'])) {
                throw new \Exception('No se pudo obtener datos del usuario');
            }

            $telefono = $this->obtenerTelefonoUsuario($meData['user']);
            $fecha    = \Carbon\Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
            $horaIni  = \Carbon\Carbon::parse($cita->hora_inicio)->format('g:i') . ' ' . (\Carbon\Carbon::parse($cita->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
            $horaFin  = \Carbon\Carbon::parse($cita->hora_fin)->format('g:i') . ' ' . (\Carbon\Carbon::parse($cita->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');

            if ($telefono) {
                $estadoMensaje = '';
                if (($cita->estado ?? 'pendiente') === 'pendiente') {
                    $estadoMensaje = "\n\n⚠️ Recuerda que la cita debe ser confirmada con 2 horas de anticipación.";

                    $mensaje = "Hola, tu cita ha sido registrada con éxito para el día {$fecha} de {$horaIni} a {$horaFin}."
                        . $estadoMensaje;
                } else {
                    $mensaje = "Hola, tu cita ha sido registrada con éxito para el día {$fecha}" .
                        "\n de {$horaIni} a {$horaFin}.";
                }

                $this->enviarMensajeAlUsuario($request, $mensaje, $telefono);
                $whatsappEnviado = true;
            }
        } catch (\Throwable $e) {
            Log::error('❌ CITA_STORE_WHATSAPP_ERROR', [
                'error'   => $e->getMessage(),
                'id_user' => $idUser,
                'cita_id' => $cita->id,
            ]);
        }

        return response()->json([
            'message'           => 'Cita registrada con éxito',
            'cita'              => $cita,
            'whatsapp_enviado'  => $whatsappEnviado, // ✅ ya no explota
        ], 201);
    }


    /**
     * 📲 Enviar mensaje de WhatsApp al usuario logueado
     */
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
            // ✅ "sometimes" = solo valida si el campo viene en el request
            'fecha'            => 'sometimes|required|date',
            'hora_inicio'      => 'sometimes|required|date_format:H:i',
            'hora_fin'         => 'sometimes|required|date_format:H:i|after:hora_inicio',
            'nombre_visitante' => 'nullable|string|max:255',
            'motivo'           => 'nullable|string|max:255',
            'estado'           => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'            => 'nullable|string',
        ]);

        // Solo checar cruce si vienen los campos de horario
        if ($request->has('fecha') && $request->has('hora_inicio') && $request->has('hora_fin')) {
            $existeCruce = Cita::where('id_user', $identity->id)
                ->where('fecha', $request->fecha)
                ->where('id', '!=', $id)
                ->where('hora_inicio', '<', $request->hora_fin)
                ->where('hora_fin', '>', $request->hora_inicio)
                ->exists();

            if ($existeCruce) {
                return response()->json(['message' => 'Conflicto de horario con otra cita'], 422);
            }
        }

        $cita->update(
            $request->only(['fecha', 'hora_inicio', 'hora_fin', 'nombre_visitante', 'motivo', 'estado', 'notas'])
        );

        return response()->json($cita);
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
}