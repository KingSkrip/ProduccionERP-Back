<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Events\Scanner\ScanEmbarqueCreado;
use Illuminate\Http\Request;
use App\Services\FirebirdConnectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScannerEmbarquesController extends Controller
{
    protected FirebirdConnectionService $firebird;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }


    public function index()
    {
        $connection = $this->firebird->getProductionConnection();
        $scans = $connection
            ->table('INVFISVSTEOPT')
            ->orderByDesc('FECHAYHORA')
            ->limit(200)
            ->get(['CODIGO', 'CODIGOENT', 'FECHAYHORA', 'PROCESADO']);

        return response()->json(['data' => $scans]);
    }


    public function scan(Request $request)
    {
        Log::info('📦 [scan] ▶ INICIO', [
            'ip'         => $request->ip(),
            'barcode'    => $request->barcode,
            'all_input'  => $request->all(),
            'userId'     => $request->firebird_user_id,
        ]);

        // ── 1. Extraer userId ──────────────────────────────────────────────────
        $userId = $request->firebird_user_id
            ?? $request->user()?->firebird_user_id
            ?? $request->user()?->ID;

        if (!$userId) {
            Log::error('❌ [scan] userId es null, no se puede broadcast');
            return response()->json(['ok' => false, 'error' => 'Sin userId'], 400);
        }


        Log::info('🎯 [scan] Broadcasting a canal:', [
            'canal' => "scanner-embarques.{$userId}",
            'userId_type' => gettype($userId),
            'userId_value' => $userId,
        ]);

        // ── 2. Validar formato del código ─────────────────────────────────────
        $codigoOriginal = trim($request->barcode);

        if (!preg_match('/^\d{10}$/', $codigoOriginal)) {
            Log::warning('⛔ [scan] Código inválido, se ignora', ['barcode' => $codigoOriginal]);
            return response()->json([
                'ok'     => false,
                'motivo' => 'codigo_invalido',
                'codigo' => $codigoOriginal,
            ], 422);
        }

        $codigoCeros = $codigoOriginal;
        $codigoLimpio = ltrim($codigoOriginal, '0') ?: '0'; // Para el cast a int en CODIGOENT
        $fechaYHora  = now()->toDateTimeString();

        // ── 3. Conexión Firebird ───────────────────────────────────────────────
        Log::info('🔌 [scan] Obteniendo conexión Firebird...');
        try {
            $connection = $this->firebird->getProductionConnection();
            Log::info('✅ [scan] Conexión Firebird OK');
        } catch (\Throwable $e) {
            Log::error('❌ [scan] Falló la conexión Firebird', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        // ── 4. Verificar duplicado ANTES de insertar ──────────────────────────────
        $yaExiste = $connection->table('INVFISVSTEOPT')
            ->where('CODIGO', $codigoCeros)
            ->where('PROCESADO', 0)
            ->exists();

        if ($yaExiste) {
            Log::info('⚠️ [scan] Código ya registrado con PROCESADO=0', ['codigo' => $codigoCeros]);
            return response()->json([
                'ok'     => false,
                'motivo' => 'duplicado',
                'codigo' => $codigoCeros,
            ], 409);
        }

        // ── 4. Insert en INVFISVSTEOPT ─────────────────────────────────────────
        $payload = [
            'CODIGO'     => $codigoCeros,
            'CODIGOENT'  => (int) $codigoLimpio,
            'FECHAYHORA' => $fechaYHora,
            'PROCESADO'  => 0,
        ];
        Log::info('💾 [scan] Insertando en INVFISVSTEOPT...', ['payload' => $payload]);

        try {
            $connection->table('INVFISVSTEOPT')->insert($payload);
            Log::info('✅ [scan] Insert OK');
        } catch (\Throwable $e) {
            Log::error('❌ [scan] Falló el insert', [
                'message' => $e->getMessage(),
                'payload' => $payload,
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Error al guardar el scan'], 500);
        }


        // ── 5. Broadcast del evento ────────────────────────────────────────────
        Log::info('📡 [scan] Disparando broadcast ScanEmbarqueCreado...', [
            'codigo'    => $codigoCeros,
            'codigoEnt' => (int) $codigoLimpio,
            'userId'    => $userId,
        ]);

        try {
            broadcast(new ScanEmbarqueCreado(
                codigo: $codigoCeros,
                codigoEnt: (int) $codigoLimpio,
                fechaYHora: $fechaYHora,
                procesado: 0,
                userId: $userId,
            ));
            Log::info('✅ [scan] Broadcast OK');
        } catch (\Throwable $e) {
            // No reventamos el request por esto, solo lo logueamos
            Log::warning('⚠️ [scan] Broadcast falló (no crítico)', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }

        // ── 6. Respuesta final ─────────────────────────────────────────────────
        Log::info('🏁 [scan] FIN OK', ['codigo' => $codigoCeros]);
        return response()->json(['ok' => true, 'codigo' => $codigoCeros]);
    }

    // ScannerEmbarquesController.php
    public function registrarOperador(Request $request)
    {
        $userId = $request->input('user_id');
        Cache::put('scanner_operador_activo', $userId, now()->addHours(8));
        return response()->json(['ok' => true, 'operador' => $userId]);
    }
}