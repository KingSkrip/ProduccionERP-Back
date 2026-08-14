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
                P.\"ESTATUS OP\",
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
                PSD.ESTATUS ESTATUS,
                COALESCE(PSD.ISDELIV,0) ISDELIV,
                PSD.FECHAYHORAINGPT AS \"FECHA ING\",
                PSD.FECHAYHORASALPT AS \"FECHA SAL\",
                PSD.FECHAYHORADEVOL AS \"FECHA DEV\",
                PSD.ID_FOL_PL AS PL
            FROM PSDTABPZAS PSD
            INNER JOIN P_PSDENC('03') P ON P.CVE_PSD_ENC = PSD.CVE_ENC
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
            WHERE COALESCE(PSD.ESTATUS,0) = 1
              AND COALESCE(PSD.ISDELIV,0) = 0
            GROUP BY
                PSD.CLAVE, P.CLAVE, P.ARTICULO, P.CLIENTE, V.AGENTE,
                P.PEDIDO, P.PARTIDA, P.\"ESTATUS OP\", P.COLOR,
                P.\"COD. COLOR\", P.FECHA, PSD.TIPO, PSD.PIEZA,
                PSD.ISDELIV, PSD.ESTATUS, PSD.FECHAYHORAINGPT,
                PSD.FECHAYHORASALPT, PSD.FECHAYHORADEVOL, PSD.ID_FOL_PL
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
                'message' => config('app.debug') ? $e->getMessage() : 'Error al obtener el inventario.',
            ], 500);
        }
    }
}