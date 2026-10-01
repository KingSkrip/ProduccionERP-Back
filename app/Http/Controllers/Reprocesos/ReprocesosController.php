<?php

namespace App\Http\Controllers\Reprocesos;

use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReprocesosController extends Controller
{
    protected FirebirdConnectionService $firebird;

    /**
     * ⚠️ Ajusta al nombre real de la tabla en Firebird.
     * (Yo asumí "REPROCESOS"; puede ser REPRO03, RRM, etc.)
     */
    private const TABLA = 'REPROCESOS';

  private const PARAM_PROC = '03';

    /** Campos a mostrar */
    private const CAMPOS = [
        'ID_RRM', 'ID_ORDENRRM', 'FECHARRM', 'TURNORRM', 'NO_RESPRRM',
        'COLORRM', 'COD_COLORRRM', 'COMPORRM', 'PROCFINRRM', 'ARTICULORRM',
        'MOTIVORRM', 'HORARRM', 'ACCIONRRM', 'AREARRM', 'AREADOS',
        'CANTRRM', 'HORALIB', 'USLIB', 'ID_TREPOR', 'STATUSRRM',
        'FECHAINSRRM', 'USINS', 'ID_FOLIO', 'STATUS_PRINT', 'STATUS_REPRINT',
        'FECHORA_STPRINT', 'USPRINT', 'FECHORA_STREPRINT', 'USREPRINT',
        'FECHINGALM', 'PESOLBRRM', 'FOLIOPLRRM', 'CANTPRIMERRM',
    ];

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }

    /* =========================
     | 📄 LISTAR REPROCESOS (STATUSRRM = 0)
     ========================= */
public function index(Request $request)
{
    $request->validate([
        'buscar' => 'nullable|string|max:100',
        'page' => 'nullable|integer|min:1',
        'per_page' => 'nullable|integer|min:1|max:200',
    ]);

    try {
        $conn = $this->firebird->getProductionConnection();
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 50);

        $rows = $conn->select(
            'SELECT * FROM P_ORDENESENCRRM(?)',
            [self::PARAM_PROC]
        );

        $data = array_map(fn ($r) => $this->limpiarFila($r), $rows);

        // Búsqueda en cualquier columna
        if ($request->filled('buscar')) {
            $q = mb_strtoupper(trim($request->buscar));

            $data = array_values(array_filter($data, function ($row) use ($q) {
                foreach ($row as $value) {
                    if ($value !== null && str_contains(mb_strtoupper((string) $value), $q)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $total = count($data);
        $data = array_slice($data, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ]);
    } catch (Throwable $e) {
        Log::error('❌ REPROCESOS_INDEX_ERROR', ['error' => $e->getMessage()]);

        return response()->json([
            'message' => 'Error al obtener los reprocesos.',
        ], 500);
    }
}

    /* =========================
     | 🔍 VER UN REPROCESO
     ========================= */
public function show($id)
{
    try {
        $conn = $this->firebird->getProductionConnection();

        $rows = $conn->select(
            'SELECT * FROM P_ORDENESENCRRM(?)',
            [self::PARAM_PROC]
        );

        foreach ($rows as $r) {
            $fila = $this->limpiarFila($r);

            if ((int) ($fila['IDREPRRM'] ?? 0) === (int) $id) {
                return response()->json($fila);
            }
        }

        return response()->json(['message' => 'Reproceso no encontrado'], 404);
    } catch (Throwable $e) {
        Log::error('❌ REPROCESOS_SHOW_ERROR', ['id' => $id, 'error' => $e->getMessage()]);

        return response()->json([
            'message' => 'Error al obtener el reproceso.',
        ], 500);
    }
}

    /* =========================
     | 👻 HELPERS
     ========================= */

    /**
     * Firebird devuelve los CHAR con espacios al final y a veces con
     * encoding raro (ej. el "¶" en MOTIVORRM). Limpio todo aquí.
     */
    private function limpiarFila(object $row): array
    {
        $out = [];

        foreach ((array) $row as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);

                if (! mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }

                $value = $value === '' ? null : $value;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}