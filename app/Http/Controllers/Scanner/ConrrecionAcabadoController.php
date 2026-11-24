<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ConrrecionAcabadoController extends Controller
{
    protected FirebirdConnectionService $firebird;

    private const ESTATUS_EDITABLE = 3;

    private const MAX_LOTE = 300;      // piezas/ids por petición

    private const MAX_OP_COMPLETA = 400; // tope al traer toda una OP

    private const MAX_ELIMINAR_LOTE = 300;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }

    /**
     * Un solo escaneo: { rollo: "0000413013" }
     * Regresa OP, PIEZA, ID del rollo y demás datos.
     */
    public function scan(Request $request)
    {
        $rollo = trim((string) $request->input('rollo', ''));
        $op = trim((string) $request->input('op', ''));
        $pieza = trim((string) $request->input('pieza', ''));

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($rollo !== '') {
                if (! preg_match('/^\d{1,10}$/', $rollo)) {
                    return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $rollo], 422);
                }
                $clave = (int) (ltrim($rollo, '0') ?: '0');
            } else {
                if ($op === '' || strlen($op) > 30) {
                    return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
                }
                if (! preg_match('/^\d{1,10}$/', $pieza)) {
                    return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $pieza], 422);
                }

                $clave = $this->claveRolloPorOpPieza($conn, $op, (int) $pieza);

                if ($clave === null) {
                    return response()->json(['ok' => false, 'motivo' => 'pieza_no_encontrada'], 404);
                }
            }

            $datos = $this->datosRolloAcabado($conn, $clave);
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] scan', ['m' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        if ($datos === null) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

        return response()->json([
            'ok' => true,
            'rollo' => str_pad((string) $clave, 10, '0', STR_PAD_LEFT), // siempre la CLAVE real
            'pieza' => $datos['PIEZA'],
            'op' => trim((string) $datos['OP']),
            'peso' => (float) $datos['PESO NETO'],
            'datos' => $datos,
        ]);
    }

    /** Busca la CLAVE del rollo a partir de OP (partida u orden) + número de pieza. */
    private function claveRolloPorOpPieza($connection, string $op, int $pieza): ?int
    {
        $r = $connection->selectOne("
        SELECT FIRST 1 PSD.CLAVE
        FROM P_PSDENC('".config('firebird.company')."') P
        LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
        INNER JOIN PSDTABPZAS PSD ON PSD.CVE_ENC = P.CVE_PSD_ENC
        WHERE (P.PARTIDA = ? OR OE.ORDEN = ?)
          AND PSD.PIEZA = ?
        ORDER BY COALESCE(PSD.ESTATUS, 1) DESC, PSD.CLAVE DESC
    ", [$op, $op, $pieza]);

        return $r ? (int) ((array) $r)['CLAVE'] : null;
    }

    private function datosRolloAcabado($connection, int $clave): ?array
    {
        try {
            // 1) Rollo directo por CLAVE (rápido)
            $row = $connection->selectOne("
        SELECT
            PSD.CLAVE AS ID,
            PSD.CVE_ENC AS CVE_ENC,
            PSD.ESTATUS AS PSD_ESTATUS,
            PSD.TIPO AS TIPO_COD,
            CASE PSD.TIPO
                WHEN 51 THEN 'PRIMERA' WHEN 52 THEN 'PREFERIDA' WHEN 73 THEN 'ORILLAS'
                WHEN 74 THEN 'RETAZO'  WHEN 77 THEN 'SEGUNDA'   WHEN 81 THEN 'MUESTRAS'
                ELSE 'OTRAS'
            END AS TIPO,
            PSD.PNETO AS \"PESO NETO\",
            PSD.PIEZA AS PIEZA,
            PSD.PBRUT AS PESO_BRUTO,
            PSD.TARA AS TARA,
            PSD.LONGITUD AS LONGITUD,
            CASE PSD.TONO
                WHEN 0 THEN 'TONO A' WHEN 1 THEN 'TONO B' WHEN 2 THEN 'TONO C'
                ELSE 'TONO ' || PSD.TONO
            END AS TONO,
            PSD.FECHAYHORA AS FECHA,
            PSD.MUESTRADELROLLO AS MUESTRA,
            COALESCE(PSD.IS_PL, 0) AS PL
        FROM PSDTABPZAS PSD
        WHERE PSD.CLAVE = ?
    ", [$clave]);

            if (! $row) {
                return null;
            }

            $row = (array) $row;

            // 2) Encabezado de la partida (cacheado)
            $enc = $this->encabezado($connection, $row['CVE_ENC']);

            if (! $enc) {
                return null;
            }

            $row = array_merge($row, $enc);

            // 3) Estatus de la orden, SIEMPRE fresco (sin caché)
            $row['OE_ESTATUS'] = $this->estatusOrden($connection, $enc['OE_ID'] ?? null);

            // Campos internos que no se mandan al front
            unset($row['CVE_ENC'], $row['OE_ID']);

            if (! empty($row['FECHA'])) {
                $row['FECHA'] = str_replace(' ', 'T', substr((string) $row['FECHA'], 0, 19));
            }

            return $row;
        } catch (\Throwable $e) {
            Log::warning('⚠️ [correccion-acabado] Falló la consulta del rollo', [
                'clave' => $clave,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function articulo(Request $request)
    {
        $op = trim((string) $request->query('op'));
        if ($op === '') {
            return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
        }

        try {
            $row = Cache::remember("ca:articulo:$op", 3600, function () use ($op) {
                $r = $this->firebird->getProductionConnection()->selectOne(
                    "SELECT FIRST 1 V.ID, V.ARTICULO, V.ANCHO, V.PESO, V.REND, V.ANCHO2, V.PESO2, V.REND2
                   FROM ORDENESENC OE
                  INNER JOIN V_ARTICULOS2NOAUT V ON V.ID = OE.CVE_ART
                  WHERE OE.ORDEN = ? AND V.TIPON = 0 AND V.NOMBRE <> ''",
                    [$op]
                );

                return $r ? (array) $r : null;
            });
        } catch (\Throwable $e) {
            Log::warning('[correccion-acabado] articulo', ['op' => $op, 'm' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'No se pudo consultar el artículo'], 500);
        }

        if (! $row) {
            return response()->json(['ok' => false, 'motivo' => 'articulo_no_encontrado'], 404);
        }

        return response()->json([
            'ok' => true,
            'articulo' => trim((string) $row['ARTICULO']),
            'ancho' => (float) $row['ANCHO'],
            'ancho_yds' => (float) $row['ANCHO2'],
            'gramaje' => (float) $row['PESO'],
            'gramaje_oz' => (float) $row['PESO2'],
            'rendimiento' => (float) $row['REND'],
            'rendimiento_yd_lb' => (float) $row['REND2'],
        ]);
    }

    public function update(Request $request)
    {
        $v = $request->validate([
            'rollo' => ['required', 'regex:/^\d{1,10}$/'],
            'kilogramos' => ['required', 'numeric', 'gt:0'],
            'libras' => ['required', 'numeric', 'gt:0'],
            'metros' => ['required', 'numeric', 'gt:0'],
            'yardas' => ['required', 'numeric', 'gt:0'],
            'tara' => ['required', 'numeric', 'min:0'],
            'pbruto' => ['required', 'numeric', 'gte:kilogramos'],
            'tipo' => ['nullable', 'integer', Rule::in([51, 52, 73, 74, 77, 81])],
        ]);

        $clave = (int) (ltrim($v['rollo'], '0') ?: '0');
        $pneto = round((float) $v['kilogramos'], 3);
        $tara = round((float) $v['tara'], 3);
        $pbrut = round((float) $v['pbruto'], 3);
        $metros = round((float) $v['metros'], 3);

        if (abs(($pneto + $tara) - $pbrut) > 0.01) {
            return response()->json(['ok' => false, 'motivo' => 'pesos_inconsistentes'], 422);
        }

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                return $bloqueo;
            }

            $sets = 'PESOKG = ?, PNETO = ?, PESOLB = ?, MTS = ?, LONGITUD = ?, YDS = ?, TARA = ?, PBRUT = ?';
            $params = [$pneto, $pneto, round((float) $v['libras'], 3), $metros, $metros,
                round((float) $v['yardas'], 3), $tara, $pbrut];

            if (isset($v['tipo'])) {
                $sets .= ', TIPO = ?';
                $params[] = (int) $v['tipo'];
            }
            $params[] = $clave;

            $afectados = $conn->update(
                "UPDATE PSDTABPZAS SET $sets WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0",
                $params
            );
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] UPDATE', ['clave' => $clave, 'm' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'No se pudo guardar'], 500);
        }

        if ($afectados < 1) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_eliminado', 'error' => 'El rollo ya fue eliminado'], 409);
        }

        return response()->json(['ok' => true]);
    }

    public function eliminar(Request $request)
    {
        $v = $request->validate([
            'rollo' => ['required', 'regex:/^\d{1,10}$/'],
        ]);

        $clave = (int) (ltrim($v['rollo'], '0') ?: '0');

        $usuario = auth()->user();
        $userId = (int) $usuario->ID;

        $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first()
            ?? UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();

        $tbClave = $identity?->firebird_tb_clave;
        $tbClave = is_string($tbClave) ? trim($tbClave) : $tbClave;

        if (! $tbClave) {
            return response()->json(['ok' => false, 'error' => 'Usuario sin clave de empleado (TB)'], 403);
        }

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                return $bloqueo;
            }

            $afectados = $conn->update(
                'UPDATE PSDTABPZAS
                SET ESTATUS = 0,
                    FECHAYHORADEL = CURRENT_TIMESTAMP,
                    USDEL = ?,
                    CASEDEL = 1
              WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0',
                [$tbClave, $clave]
            );
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] ELIMINAR', [
                'clave' => $clave,
                'tb' => $tbClave,
                'm' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'error' => 'No se pudo eliminar'], 500);
        }

        if ($afectados < 1) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_eliminado', 'error' => 'El rollo ya fue eliminado'], 409);
        }

        return response()->json(['ok' => true]);
    }

    private function encabezado($connection, $cveEnc): ?array
    {
        return Cache::remember("ca:enc2:$cveEnc", 21600, function () use ($connection, $cveEnc) {
            $e = $connection->selectOne("
            SELECT P.ARTICULO AS ARTICULO, P.PARTIDA AS OP, OE.ORDEN AS ORDEN, OE.ID AS OE_ID
            FROM P_PSDENC('".config('firebird.company')."') P
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            WHERE P.CVE_PSD_ENC = ?
        ", [$cveEnc]);

            return $e ? (array) $e : null;
        });
    }

    private function estatusOrden($connection, $oeId): ?int
    {
        if (! $oeId) {
            return null;
        }
        $r = $connection->selectOne('SELECT ESTATUS FROM ORDENESENC WHERE ID = ?', [$oeId]);

        return $r ? (int) ((array) $r)['ESTATUS'] : null;
    }

    private function validarEstatus($connection, int $clave): ?JsonResponse
    {
        $psd = $connection->selectOne(
            'SELECT CVE_ENC, ESTATUS FROM PSDTABPZAS WHERE CLAVE = ?',
            [$clave]
        );
        if (! $psd) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

        $psd = (array) $psd;

        // NUEVO: rollo ya eliminado
        if ($psd['ESTATUS'] !== null && (int) $psd['ESTATUS'] === 0) {
            return response()->json([
                'ok' => false,
                'motivo' => 'rollo_eliminado',
                'error' => 'El rollo ya fue eliminado',
            ], 409);
        }

        $enc = $this->encabezado($connection, $psd['CVE_ENC']);
        $estatus = $this->estatusOrden($connection, $enc['OE_ID'] ?? null);

        if ($estatus !== self::ESTATUS_EDITABLE) {
            return response()->json([
                'ok' => false,
                'motivo' => 'estatus_no_permitido',
                'estatus' => $estatus,
                'error' => 'La orden tiene estatus '.($estatus ?? 'desconocido').' y no se puede modificar (debe ser 3)',
            ], 409);
        }

        return null;
    }

    /** Varias piezas de una OP, toda la OP, o varios IDs. */
    public function lote(Request $request)
    {
        $v = $request->validate([
            'op' => ['nullable', 'string', 'max:30'],
            'piezas' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'piezas.*' => ['integer', 'min:0'],
            'claves' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'claves.*' => ['regex:/^\d{1,10}$/'],
        ]);

        $op = trim((string) ($v['op'] ?? ''));
        $piezas = array_values(array_unique(array_map('intval', $v['piezas'] ?? [])));
        $faltan = [];

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($op !== '') {
                $porPieza = $this->clavesPorOp($conn, $op, $piezas);
                if (! $porPieza) {
                    return response()->json(['ok' => false, 'motivo' => 'pieza_no_encontrada'], 404);
                }
                $claves = array_values($porPieza);
                if ($piezas) {
                    $faltan = array_values(array_diff($piezas, array_keys($porPieza)));
                }
            } elseif (! empty($v['claves'])) {
                $claves = array_values(array_unique(array_map(
                    fn ($c) => (int) (ltrim((string) $c, '0') ?: '0'),
                    $v['claves']
                )));
            } else {
                return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
            }

            $rollos = [];
            foreach ($claves as $clave) {
                $datos = $this->datosRolloAcabado($conn, $clave);
                if ($datos === null) {
                    $faltan[] = $op !== '' ? $clave : str_pad((string) $clave, 10, '0', STR_PAD_LEFT);

                    continue;
                }
                $rollos[] = [
                    'ok' => true,
                    'rollo' => str_pad((string) $clave, 10, '0', STR_PAD_LEFT),
                    'pieza' => $datos['PIEZA'],
                    'op' => trim((string) $datos['OP']),
                    'peso' => (float) $datos['PESO NETO'],
                    'datos' => $datos,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] lote', ['m' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        return response()->json([
            'ok' => true,
            'rollos' => $rollos,
            'no_encontrados' => $faltan,
        ]);
    }

    public function scanLote(Request $request)
    {
        $v = $request->validate([
            'op' => ['nullable', 'string', 'max:30', 'required_without:claves'],
            'piezas' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'piezas.*' => ['integer', 'min:0', 'max:9999999999'],
            'claves' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'claves.*' => ['integer', 'min:0', 'max:9999999999'],
        ]);

        $op = trim((string) ($v['op'] ?? ''));
        $piezas = array_values(array_unique(array_map('intval', $v['piezas'] ?? [])));
        $claves = array_values(array_unique(array_map('intval', $v['claves'] ?? [])));

        $items = [];
        $noEncontradas = [];
        $truncado = false;

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($claves) {
                // modo IDs: [clave => clave]
                $mapa = array_combine($claves, $claves);
            } else {
                $mapa = $this->clavesPorOp($conn, $op, $piezas);   // [pieza => clave]
                $noEncontradas = $piezas ? array_values(array_diff($piezas, array_keys($mapa))) : [];

                if (! $piezas && ! $mapa) {
                    return response()->json(['ok' => false, 'motivo' => 'op_sin_rollos'], 404);
                }
                if (count($mapa) > self::MAX_OP_COMPLETA) {
                    $mapa = array_slice($mapa, 0, self::MAX_OP_COMPLETA, true);
                    $truncado = true;
                }
            }

            foreach ($mapa as $llave => $clave) {
                $datos = $this->datosRolloAcabado($conn, $clave);
                if ($datos === null) {
                    $noEncontradas[] = $llave;

                    continue;
                }
                $items[] = $this->formatoRollo($clave, $datos);
            }
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] scanLote', ['m' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        return response()->json([
            'ok' => true,
            'total' => count($items),
            'items' => $items,
            'no_encontradas' => $noEncontradas,
            'truncado' => $truncado,
        ]);
    }

    /** [pieza => clave]. Con piezas filtra por ellas; sin piezas trae toda la OP (sin eliminados). */
    private function clavesPorOp($connection, string $op, array $piezas): array
    {
        $params = [$op, $op];
        if ($piezas) {
            $filtro = ' AND PSD.PIEZA IN ('.implode(',', array_fill(0, count($piezas), '?')).')';
            array_push($params, ...$piezas);
        } else {
            $filtro = ' AND COALESCE(PSD.ESTATUS, 1) <> 0';
        }

        $rows = $connection->select("
        SELECT PSD.CLAVE, PSD.PIEZA
        FROM P_PSDENC('".config('firebird.company')."') P
        LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
        INNER JOIN PSDTABPZAS PSD ON PSD.CVE_ENC = P.CVE_PSD_ENC
        WHERE (P.PARTIDA = ? OR OE.ORDEN = ?) $filtro
        ORDER BY PSD.PIEZA, COALESCE(PSD.ESTATUS, 1) DESC, PSD.CLAVE DESC
    ", $params);

        $mapa = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $p = (int) $r['PIEZA'];
            $mapa[$p] ??= (int) $r['CLAVE']; // si hay pieza duplicada, gana la activa
        }
        ksort($mapa);

        return $mapa;
    }

    private function formatoRollo(int $clave, array $datos): array
    {
        return [
            'ok' => true,
            'rollo' => str_pad((string) $clave, 10, '0', STR_PAD_LEFT),
            'pieza' => $datos['PIEZA'],
            'op' => trim((string) $datos['OP']),
            'peso' => (float) $datos['PESO NETO'],
            'datos' => $datos,
        ];
    }

    /** Clave de empleado (TB) del usuario logueado, o null. */
    private function tbClaveUsuario(): ?string
    {
        $usuario = auth()->user();
        $userId = (int) $usuario->ID;

        $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first()
            ?? UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();

        $tb = $identity?->firebird_tb_clave;
        $tb = is_string($tb) ? trim($tb) : $tb;

        return $tb ?: null;
    }

    /** Elimina (baja lógica) varios rollos de golpe. Responde por rollo. */
    public function eliminarLote(Request $request)
    {
        $v = $request->validate([
            'rollos' => ['required', 'array', 'min:1', 'max:'.self::MAX_ELIMINAR_LOTE],
            'rollos.*' => ['regex:/^\d{1,10}$/'],
        ]);

        $tbClave = $this->tbClaveUsuario();
        if (! $tbClave) {
            return response()->json(['ok' => false, 'error' => 'Usuario sin clave de empleado (TB)'], 403);
        }

        $eliminados = [];
        $fallidos = [];

        try {
            $conn = $this->firebird->getProductionConnection();

            foreach (array_unique($v['rollos']) as $rollo) {
                $clave = (int) (ltrim((string) $rollo, '0') ?: '0');
                $id = str_pad((string) $clave, 10, '0', STR_PAD_LEFT);

                try {
                    // mismas reglas que eliminar(): existe, no eliminado, orden en estatus 3
                    if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                        $d = $bloqueo->getData(true);
                        $fallidos[] = [
                            'rollo' => $id,
                            'motivo' => $d['motivo'] ?? null,
                            'estatus' => $d['estatus'] ?? null,
                            'error' => $d['error'] ?? null,
                        ];

                        continue;
                    }

                    $afectados = $conn->update(
                        'UPDATE PSDTABPZAS
                        SET ESTATUS = 0,
                            FECHAYHORADEL = CURRENT_TIMESTAMP,
                            USDEL = ?,
                            CASEDEL = 1
                      WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0',
                        [$tbClave, $clave]
                    );

                    if ($afectados < 1) {
                        $fallidos[] = ['rollo' => $id, 'motivo' => 'rollo_eliminado'];
                    } else {
                        $eliminados[] = $id;
                    }
                } catch (\Throwable $e) {
                    Log::error('[correccion-acabado] ELIMINAR LOTE item', [
                        'clave' => $clave, 'tb' => $tbClave, 'm' => $e->getMessage(),
                    ]);
                    $fallidos[] = ['rollo' => $id, 'error' => 'No se pudo eliminar'];
                }
            }
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] ELIMINAR LOTE', ['m' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        Log::info('[correccion-acabado] ELIMINAR LOTE', [
            'tb' => $tbClave, 'eliminados' => count($eliminados), 'fallidos' => count($fallidos),
        ]);

        return response()->json([
            'ok' => true,
            'eliminados' => $eliminados,
            'fallidos' => $fallidos,
        ]);
    }
}
