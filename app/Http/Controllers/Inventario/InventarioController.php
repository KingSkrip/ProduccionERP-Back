<?php

namespace App\Http\Controllers\Inventario;

use App\Exceptions\Inventario\CodigoEscaneoInvalidoException;
use App\Exceptions\Inventario\RolloNoEncontradoException;
use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use App\Services\Inventario\EscaneoRolloService;
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
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al consultar inventario (Firebird): '.$e->getMessage());

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
    public function escanear(Request $request, EscaneoRolloService $servicio)
    {
        $request->validate([
            'codigo' => 'required|string|max:20',
        ]);

        $raw = trim($request->input('codigo'));

        try {
            $row = $servicio->escanear($raw);

            return response()->json([
                'success' => true,
                'data' => $row,
            ]);
        } catch (CodigoEscaneoInvalidoException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Código QR inválido.',
            ], 422);
        } catch (RolloNoEncontradoException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error al obtener el detalle del rollo.',
            ], 500);
        }
    }
}