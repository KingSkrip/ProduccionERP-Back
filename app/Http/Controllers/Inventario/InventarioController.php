<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InventarioController extends Controller
{
    public function __construct(
        protected FirebirdConnectionService $firebird
    ) {}

    public function index(Request $request)
    {
        $sql = "
            SELECT
                LPAD(PSD.CLAVE,10,'0') ID,
                P.CLAVE AS \"CVE ART\",
                P.ARTICULO ARTICULO,
                P.CLIENTE CLIENTE,
                COALESCE(V.agente,'SIN AGENTE') AGENTE,
                P.PEDIDO,
                P.PARTIDA OP,
                OE.PEDIDOPART,
                P.\"COD. COLOR\" AS \"COD. COLOR\",
                P.COLOR COLOR,
                P.FECHA FECHA,
                CASE PSD.TIPO
                    WHEN 51 THEN 'PRIMERA'
                    WHEN 52 THEN 'PREFERIDA'
                    WHEN 73 THEN 'ORILLAS'
                    WHEN 74 THEN 'RETAZO'
                    WHEN 77 THEN 'SEGUNDA'
                    WHEN 81 THEN 'MUESTRA'
                    ELSE 'OTRAS'
                END TIPO,
                SUM(PSD.PNETO) AS \"PESO NETO\",
                PSD.PIEZA PIEZA,
                PSD.FECHAYHORAINGPT AS \"FECHA ING\",
                PSD.FECHAYHORASALPT AS \"FECHA SAL\",
                PSD.FECHAYHORADEVOL AS \"FECHA DEV\",
                PSD.ID_FOL_PL AS PL,
                IIF(OE.ESTATUS = 2, S.PROCESO, E.ESTATUS) AS PROCESO,
                IIF(
                    PSD.FECHAYHORADEVOL IS NOT NULL,
                    '',
                    IIF(OE.ESTATUS IN (4, 50, 51, 61, 65), 'ROLLO', 'TELA')
                ) AS PRODUCTO
            FROM PSDTABPZAS PSD
            INNER JOIN P_PSDENC('03') P ON P.CVE_PSD_ENC = PSD.CVE_ENC
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
            LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
            LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
            LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
            WHERE COALESCE(PSD.ESTATUS,0) = 1
              AND COALESCE(PSD.ISDELIV,0) = 0
              AND PSD.TIPO = 51
            GROUP BY
                PSD.CLAVE,
                P.CLAVE,
                P.ARTICULO,
                P.CLIENTE,
                V.AGENTE,
                P.PEDIDO,
                P.PARTIDA,
                OE.PEDIDOPART,
                P.COLOR,
                P.\"COD. COLOR\",
                P.FECHA,
                PSD.TIPO,
                PSD.PIEZA,
                PSD.FECHAYHORAINGPT,
                PSD.FECHAYHORASALPT,
                PSD.FECHAYHORADEVOL,
                PSD.ID_FOL_PL,
                OE.ESTATUS,
                S.PROCESO,
                E.ESTATUS
            ORDER BY P.ARTICULO ASC
        ";

        try {
            $data = $this->firebird
                ->getProductionConnection()
                ->select($sql);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al consultar inventario (Firebird): ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error al obtener el inventario.',
            ], 500);
        }
    }


 /**
     * Buscar el detalle completo de un rollo a partir del código QR escaneado.
     * El QR trae la CLAVE con ceros a la izquierda hasta 10 dígitos (ej. "0000413014"),
     * pero en PSDTABPZAS.CLAVE está guardada como entero sin esos ceros.
     */
    public function escanear(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string|max:20',
        ]);

        $raw = trim($request->input('codigo'));

        // 🔥 Quitar ceros dinámicos a la izquierda y castear a entero
        $sinCeros = ltrim($raw, '0');
        $clave = $sinCeros === '' ? 0 : (int) $sinCeros;

        if ($clave <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Código QR inválido.',
            ], 422);
        }

        // 🔎 Todos los detalles del rollo — mismo esqueleto del index()
        // pero filtrado por CLAVE exacta y sin GROUP BY (un solo registro)
        $sql = "
            SELECT
                PSD.CLAVE AS ID,
                LPAD(PSD.CLAVE,10,'0') AS ID_QR,
                P.CLAVE AS \"CVE ART\",
                P.ARTICULO AS ARTICULO,
                P.CLIENTE AS CLIENTE,
                COALESCE(V.agente,'SIN AGENTE') AS AGENTE,
                P.PEDIDO AS PEDIDO,
                P.PARTIDA AS OP,
                OE.PEDIDOPART AS PEDIDOPART,
                P.\"COD. COLOR\" AS \"COD. COLOR\",
                P.COLOR AS COLOR,
                P.FECHA AS FECHA,
                PSD.TIPO AS TIPO_COD,
                CASE PSD.TIPO
                    WHEN 51 THEN 'PRIMERA'
                    WHEN 52 THEN 'PREFERIDA'
                    WHEN 73 THEN 'ORILLAS'
                    WHEN 74 THEN 'RETAZO'
                    WHEN 77 THEN 'SEGUNDA'
                    WHEN 81 THEN 'MUESTRA'
                    ELSE 'OTRAS'
                END AS TIPO,
                PSD.PNETO AS \"PESO NETO\",
                PSD.PIEZA AS PIEZA,
                PSD.ESTATUS AS PSD_ESTATUS,
                PSD.ISDELIV AS ISDELIV,
                PSD.FECHAYHORAINGPT AS \"FECHA ING\",
                PSD.FECHAYHORASALPT AS \"FECHA SAL\",
                PSD.FECHAYHORADEVOL AS \"FECHA DEV\",
                PSD.ID_FOL_PL AS PL,
                OE.ORDEN AS ORDEN,
                OE.ESTATUS AS OE_ESTATUS,
                IIF(OE.ESTATUS = 2, S.PROCESO, E.ESTATUS) AS PROCESO,
                IIF(
                    PSD.FECHAYHORADEVOL IS NOT NULL,
                    '',
                    IIF(OE.ESTATUS IN (4, 50, 51, 61, 65), 'ROLLO', 'TELA')
                ) AS PRODUCTO
            FROM PSDTABPZAS PSD
            INNER JOIN P_PSDENC('03') P ON P.CVE_PSD_ENC = PSD.CVE_ENC
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
            LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
            LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
            LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
            WHERE PSD.CLAVE = ?
        ";

        try {
            $row = $this->firebird
                ->getProductionConnection()
                ->selectOne($sql, [$clave]);

            if (! $row) {
                Log::warning('⚠️ INVENTARIO_QR_NO_ENCONTRADO', [
                    'codigo_qr' => $raw,
                    'clave_buscada' => $clave,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "No se encontró ningún rollo con la clave {$clave}.",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $row,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al escanear QR de inventario (Firebird): '.$e->getMessage(), [
                'codigo_qr' => $raw,
                'clave' => $clave,
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error al obtener el detalle del rollo.',
            ], 500);
        }
    }
}