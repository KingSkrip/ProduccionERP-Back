<?php

// app/Services/Inventario/EscaneoRolloService.php

namespace App\Services\Inventario;

use App\Exceptions\Inventario\CodigoEscaneoInvalidoException;
use App\Exceptions\Inventario\RolloNoEncontradoException;
use App\Services\FirebirdConnectionService;
use Illuminate\Support\Facades\Log;

class EscaneoRolloService
{
    public function __construct(
        private readonly FirebirdConnectionService $firebird,
    ) {}

    /**
     * Punto de entrada único: recorre PESADO -> REVISADO -> ACABADO
     * y devuelve el primer rollo que encuentre.
     */
    public function escanear(string $codigoRaw): array
    {
        // Validamos una sola vez aquí para no repetir el error de "código inválido"
        // en cada uno de los 3 pasos.
        $clave = $this->normalizarClave($codigoRaw);

        $pasos = [
            'PESADO' => fn () => $this->escanearPesado($codigoRaw),
            'REVISADO' => fn () => $this->escanearRevisado($codigoRaw),
            'ACABADO' => fn () => $this->escanearAcabado($codigoRaw),
        ];

        foreach ($pasos as $nombrePaso => $callback) {
            try {
                return $callback();
            } catch (RolloNoEncontradoException) {
                Log::info("🔄 INVENTARIO_QR_NO_ENCONTRADO_EN_{$nombrePaso}_PROBANDO_SIGUIENTE", [
                    'codigo_qr' => $codigoRaw,
                    'clave_buscada' => $clave,
                ]);

                // seguimos al siguiente paso del recorrido
                continue;
            }
            // Cualquier otra excepción (error real de Firebird, etc.) se propaga
            // de inmediato y NO sigue probando los demás pasos.
        }

        // No se encontró en ninguno de los 3 pasos
        Log::warning('⚠️ INVENTARIO_QR_NO_ENCONTRADO_EN_NINGUN_PASO', [
            'codigo_qr' => $codigoRaw,
            'clave_buscada' => $clave,
        ]);

        throw new RolloNoEncontradoException($clave);
    }

    /**
     * Paso 1 del recorrido: escaneo en PESADO.
     * TODO: pegar aquí el SQL real — mismo patrón que escanearAcabado().
     */
    public function escanearPesado(string $codigoRaw): array
    {
        $clave = $this->normalizarClave($codigoRaw);

        // ── Paso 1: PSDTABPZASTJ — obtenemos OT_PSD (no existe CLAVE aquí, es ID)
        $sqlPaso1 = '
        SELECT
            PJ.ID AS ID,
            PJ.OT_PSD AS OT_PSD
        FROM PSDTABPZASTJ PJ
        WHERE PJ.ID = ?
    ';

        $rowPaso1 = $this->ejecutar($sqlPaso1, [$clave], 'PESADO-PASO1', $codigoRaw, $clave);

        if (! $rowPaso1 || empty($rowPaso1['OT_PSD'])) {
            throw new RolloNoEncontradoException($clave);
        }

        $otPsd = $rowPaso1['OT_PSD'];

        // ── Paso 2: ORDENESTEJ — aquí SÍ viven CANT, CANTENT, ESTATUS y OP
        $sqlPaso2 = '
        SELECT
            OT.OP AS OP,
            OT.CANT AS CANT,
            OT.CANTENT AS CANTENT,
            OT.ESTATUS AS ESTATUS
        FROM ORDENESTEJ OT
        WHERE OT.OT = ?
    ';

        $rowPaso2 = $this->ejecutar($sqlPaso2, [$otPsd], 'PESADO-PASO2', $codigoRaw, $clave);

        if (! $rowPaso2 || empty($rowPaso2['OP'])) {
            throw new RolloNoEncontradoException($clave);
        }

        // 🔎 Filtro: solo sigue si YA terminó de pesarse (CANTENT >= CANT) y ESTATUS = 51.
        // Si no cumple, lo tratamos como "no encontrado en PESADO" para que el
        // recorrido pase a REVISADO.
        $cantEnt = (float) ($rowPaso2['CANTENT'] ?? 0);
        $cant = (float) ($rowPaso2['CANT'] ?? 0);
        $estatus = (int) ($rowPaso2['ESTATUS'] ?? 0);

        if ($cantEnt < $cant || $estatus !== 51) {
            Log::info('🔄 INVENTARIO_PESADO_NO_CUMPLE_FILTRO', [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
                'cantent' => $cantEnt,
                'cant' => $cant,
                'estatus' => $estatus,
            ]);

            throw new RolloNoEncontradoException($clave);
        }

        $op = $rowPaso2['OP'];

        // ── Paso 3: mismo esqueleto que ACABADO, pero entrando por ORDENESENC.ORDEN = OP
        // (el OP de ORDENESTEJ coincide con ORDENESENC.ORDEN, no con P.PARTIDA)
        $sqlPaso3 = "
        SELECT
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
            OE.ORDEN AS ORDEN,
            OE.ESTATUS AS OE_ESTATUS,
            IIF(OE.ESTATUS = 2, S.PROCESO, E.ESTATUS) AS PROCESO
        FROM ORDENESENC OE
        INNER JOIN P_PSDENC('03') P ON P.CVE_ORDEN = OE.ID
        LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
        LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
        LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
        LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
        WHERE OE.ORDEN = ?
    ";

        $row = $this->ejecutar($sqlPaso3, [$op], 'PESADO-PASO3', $codigoRaw, $clave);

        if (! $row) {
            throw new RolloNoEncontradoException($clave);
        }

        $row['ID'] = $rowPaso1['ID'];
        $row['ID_QR'] = str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT);
        $row['ORIGEN'] = 'PESADO';

        return $row;
    }

    /**
     * Paso 2 del recorrido: escaneo en REVISADO.
     * TODO: pegar aquí el SQL real — mismo patrón que escanearAcabado().
     */
    public function escanearRevisado(string $codigoRaw): array
    {
        $clave = $this->normalizarClave($codigoRaw);

        // ── Paso 1: PSDTABPZASTJ — checamos si ya fue revisado (aquí también es ID, no CLAVE)
        $sqlPaso1 = '
        SELECT
            PJ.ID AS ID,
            PJ.ISREV AS ISREV,
            PJ.PESORV AS PESORV,
            PJ.CVE_ORDEN AS CVE_ORDEN,
            PJ.CVE_ORDEN_OP AS CVE_ORDEN_OP,
            PJ.ID_FOLCDO_PL AS ID_FOLCDO_PL
        FROM PSDTABPZASTJ PJ
        WHERE PJ.ID = ?
    ';

        $rowPaso1 = $this->ejecutar($sqlPaso1, [$clave], 'REVISADO-PASO1', $codigoRaw, $clave);

        if (! $rowPaso1) {
            throw new RolloNoEncontradoException($clave);
        }

        $isRev = (int) ($rowPaso1['ISREV'] ?? 0);
        $pesoRv = $rowPaso1['PESORV'] ?? null;

        if ($isRev !== 1 && empty($pesoRv)) {
            Log::info('🔄 INVENTARIO_REVISADO_NO_CUMPLE_FILTRO', [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
                'isrev' => $isRev,
                'pesorv' => $pesoRv,
            ]);

            throw new RolloNoEncontradoException($clave);
        }

        $cveOrden = $rowPaso1['CVE_ORDEN'];

        if (empty($cveOrden)) {
            throw new RolloNoEncontradoException($clave);
        }

        $ordenSurte = $rowPaso1['CVE_ORDEN_OP'] ?? null;
        $folioVentaDirecta = $rowPaso1['ID_FOLCDO_PL'] ?? null;
        $yaSurtido = ! empty($ordenSurte) || ! empty($folioVentaDirecta);

        // ── Paso 2: ORDENESENC + P_PSDENC — aquí CVE_ORDEN SÍ es igual a ORDENESENC.ID
        $sqlPaso2 = "
        SELECT
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
            OE.ORDEN AS ORDEN,
            OE.ESTATUS AS OE_ESTATUS,
            IIF(OE.ESTATUS = 2, S.PROCESO, E.ESTATUS) AS PROCESO
        FROM ORDENESENC OE
        INNER JOIN P_PSDENC('03') P ON P.CVE_ORDEN = OE.ID
        LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
        LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
        LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
        LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
        WHERE OE.ID = ?
    ";

        $row = $this->ejecutar($sqlPaso2, [$cveOrden], 'REVISADO-PASO2', $codigoRaw, $clave);

        if (! $row) {
            throw new RolloNoEncontradoException($clave);
        }

        $row['ID'] = $rowPaso1['ID'];
        $row['ID_QR'] = str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT);
        $row['SURTIDO'] = $yaSurtido;
        $row['ORIGEN'] = 'REVISADO';

        return $row;
    }

    /**
     * Paso 3 del recorrido: escaneo en ACABADO.
     */
    public function escanearAcabado(string $codigoRaw): array
    {
        $clave = $this->normalizarClave($codigoRaw);

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

        $row = $this->ejecutar($sql, [$clave], 'ACABADO', $codigoRaw, $clave);

        if (! $row) {
            throw new RolloNoEncontradoException($clave);
        }

        $row['ORIGEN'] = 'ACABADO';

        return $row;
    }

    /**
     * Limpia ceros a la izquierda y castea a entero. Lanza excepción si queda inválido.
     */
    private function normalizarClave(string $raw): int
    {
        $raw = trim($raw);
        $sinCeros = ltrim($raw, '0');
        $clave = $sinCeros === '' ? 0 : (int) $sinCeros;

        if ($clave <= 0) {
            throw new CodigoEscaneoInvalidoException($raw);
        }

        return $clave;
    }

    /**
     * Wrapper único para correr el query y loggear errores de Firebird de forma consistente
     * entre los 3 pasos.
     */
    private function ejecutar(string $sql, array $bindings, string $paso, string $codigoRaw, int $clave): ?array
    {
        try {
            return $this->firebird->getProductionConnection()->selectOne($sql, $bindings);
        } catch (\Throwable $e) {
            Log::error("Error al escanear rollo ({$paso}) en Firebird: ".$e->getMessage(), [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
            ]);

            throw $e;
        }
    }
}
