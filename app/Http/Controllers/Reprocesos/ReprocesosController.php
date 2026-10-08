<?php

namespace App\Http\Controllers\Reprocesos;

use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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

    // private const PARAM_PROC = '03';

    private function paramProc(): string
    {
        return config('firebird.company');
    }

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
                [config('firebird.company')]
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
                [config('firebird.company')]
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

    /* =========================
 | ✅ LIBERAR REPROCESO
 ========================= */
    public function liberar(Request $request, $id)
    {
        try {
            $userId = $this->obtenerUsuarioId($request);

            if (! $userId) {
                return response()->json(['message' => 'No autenticado'], 401);
            }

            $conn = $this->firebird->getProductionConnection();

            // 1) Buscar la fila en el SP para obtener ORDEN e IDREPRRM
            $rows = $conn->select(
                'SELECT * FROM P_ORDENESENCRRM(?)',
                [config('firebird.company')]
            );

            $fila = null;
            foreach ($rows as $r) {
                $f = $this->limpiarFila($r);
                if ((int) ($f['IDREPRRM'] ?? 0) === (int) $id) {
                    $fila = $f;
                    break;
                }
            }

            if (! $fila) {
                return response()->json(['message' => 'Reproceso no encontrado'], 404);
            }

            $orden = $fila['ORDEN'];
            $idRepRrm = (int) $fila['IDREPRRM'];
            $tipoReporte = $fila['TIPO_REPORTE'] ?? null;
            $horaLib = now()->format('Y-m-d H:i:s'); // si HORALIB es TIMESTAMP usa 'Y-m-d H:i:s'

            $tipoNorm = mb_strtoupper(trim((string) $tipoReporte));
            $otGenerado = null;
            $accion = null; // 'TEJE' | 'SURTE' | null

            $trabajo = function () use ($conn, $orden, $idRepRrm, $horaLib, $userId, $tipoNorm, &$otGenerado, &$accion) {
                $conn->update(
                    'UPDATE ORDENESENC SET ORDLIBERAR = 1 WHERE ORDEN = ?',
                    [$orden]
                );

                $afectadas = $conn->update(
                    'UPDATE REPORTEERRM
        SET STATUSRRM = 1,
            HORALIB = CAST(? AS TIMESTAMP),
            USLIB = ?
      WHERE ID_RRM = ? AND STATUSRRM = 0',
                    [$horaLib, $userId, $idRepRrm]
                );
                if ($afectadas === 0) {
                    throw new \RuntimeException('El reproceso ya fue liberado.');
                }

                // Orden de tejido solo para COMPLEMENTO / REPOSICION
                if (in_array($tipoNorm, ['COMPLEMENTO', 'REPOSICION'], true)) {
                    if ($this->tieneProcesoST($conn, $orden)) {
                        $accion = 'SURTE';
                    } else {
                        $accion = 'TEJE';
                        $otGenerado = $this->generarOrdenTejido($conn, $orden);
                    }
                }
            };

            $pdo = $conn->getPdo();

            if ($pdo->inTransaction()) {
                // PDO ya traía una transacción abierta: la usamos y cerramos nosotros
                try {
                    $trabajo();
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } else {
                $conn->transaction($trabajo);
            }

            Log::info('✅ REPROCESO_LIBERADO', [
                'idreprrm' => $idRepRrm,
                'orden' => $orden,
                'tipo_reporte' => $tipoReporte,
                'accion' => $accion,
                'ot' => $otGenerado,
                'uslib' => $userId,
            ]);

            return response()->json([
                'message' => 'Reproceso liberado correctamente.',
                'orden' => $orden,
                'idreprrm' => $idRepRrm,
                'tipo_reporte' => $tipoReporte,
                'accion' => $accion,
                'ot' => $otGenerado,
                'horalib' => $horaLib,
                'uslib' => $userId,
            ]);
        } catch (Throwable $e) {
            Log::error('❌ REPROCESOS_LIBERAR_ERROR', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al liberar el reproceso.',
            ], 500);
        }
    }

    private function obtenerUsuarioId(Request $request): ?int
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key(config('jwt.secret'), 'HS256'));

            return (int) $decoded->sub;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * true si la ruta de la orden tiene el proceso ST (se surte, no se teje).
     */
    private function tieneProcesoST($conn, string $orden): bool
    {
        $row = $conn->selectOne(
            "SELECT FIRST 1 ORDEN FROM ORDENESPROC WHERE ORDEN = ? AND PROC = 'ST'",
            [$orden]
        );

        return $row !== null;
    }

    /**
     * Crea la orden de tejido (ORDENESTEJ) para una orden de reposición/complemento.
     * Devuelve el OT generado (ej. A000123) o null si la ruta no lleva TJ/MAQTJ.
     */
    private function generarOrdenTejido($conn, string $orden): ?string
    {
        // 1) Datos de la orden
        $oe = $conn->selectOne(
            'SELECT OE.ID, OE.CVE_ART, OE.CANTIDAD, OE.ROLLOS, OE.AGENTE,
                OE.CVE_PED, OE.PEDIDOPART
           FROM ORDENESENC OE
           LEFT JOIN V_ARTSENC VA ON VA.NID = OE.CVE_ART
          WHERE OE.ORDEN = ?',
            [$orden]
        );

        if (! $oe) {
            throw new \RuntimeException("No se encontró la orden {$orden} en ORDENESENC.");
        }

        $cveOrden = (int) $oe->ID;
        $cveArt = (int) $oe->CVE_ART;

        // 2) Hilos y porcentajes (máximo 4, el resto en 0)
        $hilos = $conn->select(
            'SELECT CONS, HILO, PORC FROM ARTICULOSH WHERE CVE_ART = ? ORDER BY CONS ASC',
            [$cveArt]
        );

        $h = [0, 0, 0, 0];
        $p = [0, 0, 0, 0];
        foreach (array_slice($hilos, 0, 4) as $i => $fila) {
            $h[$i] = $fila->HILO;
            $p[$i] = $fila->PORC;
        }

        // 3) ¿La ruta tiene TJ o MAQTJ?
        $tj = $conn->selectOne(
            "SELECT COUNT(*) AS TOTAL FROM ORDENESPROC
          WHERE CVE_ORDEN = ? AND PROC IN ('TJ', 'MAQTJ')",
            [$cveOrden]
        );

        if ((int) ($tj->TOTAL ?? 0) < 1) {
            return null;
        }

        // 4) Folio: primero incrementa (bloquea la fila) y luego lee el nuevo valor
        $conn->update('UPDATE FOLIOS SET FOLIO = FOLIO + 1 WHERE ID = 7');
        $eOT = (int) $conn->selectOne('SELECT FOLIO FROM FOLIOS WHERE ID = 7')->FOLIO;
        $ot = 'A'.str_pad((string) $eOT, 6, '0', STR_PAD_LEFT);

        // 5) Insertar/actualizar la orden de tejido
        $conn->statement(
            'UPDATE OR INSERT INTO ORDENESTEJ
            (CVE_ORDEN, OT, ESTATUS, CVE_ART, CANT, PZAS, FECHAELAB,
             CVE_HILO1, CVE_HILO2, CVE_HILO3, CVE_HILO4,
             PORCH1, PORCH2, PORCH3, PORCH4,
             CVE_AGT, OP, CVE_PED, PART_PED)
         VALUES (?, ?, 0, ?, ?, ?, CURRENT_TIMESTAMP,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?)',
            [
                $cveOrden, $ot, $cveArt, $oe->CANTIDAD, $oe->ROLLOS,
                $h[0], $h[1], $h[2], $h[3],
                $p[0], $p[1], $p[2], $p[3],
                $oe->AGENTE, $orden, $oe->CVE_PED, $oe->PEDIDOPART,
            ]
        );

        return $ot;
    }
}
