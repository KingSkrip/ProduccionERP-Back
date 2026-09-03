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
        $clave = $this->normalizarClave($codigoRaw);

        // 1 sola consulta ligera para decidir la rama, en vez de ir
        // probando PESADO y REVISADO a ciegas.
        if ($this->existeEnProcesoTejido($clave)) {
            // Solo puede estar en PESADO o REVISADO — nunca en ACABADO
            // (evita el bug de colisión de claves, ej. ID 259006).
            try {
                return $this->escanearPesado($codigoRaw);
            } catch (RolloNoEncontradoException) {
                Log::info('🔄 INVENTARIO_QR_NO_ENCONTRADO_EN_PESADO_PROBANDO_REVISADO', [
                    'codigo_qr' => $codigoRaw,
                    'clave_buscada' => $clave,
                ]);
            }

            return $this->escanearRevisado($codigoRaw); // deja que lance si no aplica
        }

        // No está en tejido → directo a ACABADO, sin gastar 2 queries de más.
        return $this->escanearAcabado($codigoRaw);
    }

    /**
     * Verifica si la pieza existe en PSDTABPZASTJ (tejido/pesado/revisado),
     * sin importar en qué estado esté. Se usa como guardia antes de intentar
     * ACABADO, ya que PSDTABPZASTJ.ID y PSDTABPZAS.CLAVE son numeraciones
     * de tablas distintas, sin relación entre sí.
     */
    private function existeEnProcesoTejido(int $clave): bool
    {
        $sql = 'SELECT PJ.ID FROM PSDTABPZASTJ PJ WHERE PJ.ID = ?';

        $row = $this->ejecutar($sql, [$clave], 'CHECK-EXISTE-TEJIDO', (string) $clave, $clave);

        return $row !== null;
    }

    /**
     * Paso 1 del recorrido: escaneo en PESADO.
     * TODO: pegar aquí el SQL real — mismo patrón que escanearAcabado().
     */
    public function escanearPesado(string $codigoRaw): array
    {
        $clave = $this->normalizarClave($codigoRaw);

        // ── Paso 1: PSDTABPZASTJ — obtenemos OT_PSD, estado de revisión y datos
        // ampliados de la pieza (maquina, fecha, articulo, tejido, hilatura).
        $sqlPaso1 = '
        SELECT
            PJ.ID AS ID,
            PJ.OT_PSD AS OT_PSD,
            PJ.ISREV AS ISREV,
            PJ.PESORV AS PESORV,
            PJ.PZA AS PIEZA,
            PJ.PESOTJ AS PESO_TEJIDO,
            PJ.CVE_ART AS CVE_ART,
            PJ.CE AS MAQUINA,
            PJ.FECHAYHORAPSD AS FECHA_PESADO,
            ART.NOMBRE AS NOMBRE,
            T.TEJIDO AS TEJIDO,
            H.CODIGO AS HILATURA
        FROM PSDTABPZASTJ PJ
        LEFT JOIN ARTICULOS ART ON PJ.CVE_ART = ART.ID
        LEFT JOIN TEJIDO T ON T.ID = ART.TEJ
        LEFT JOIN HILATURA H ON H.ID = ART.HILAT
        WHERE PJ.ID = ?
        ';

        $rowPaso1 = $this->ejecutar($sqlPaso1, [$clave], 'PESADO-PASO1', $codigoRaw, $clave);

        if (! $rowPaso1 || empty($rowPaso1['OT_PSD'])) {
            throw new RolloNoEncontradoException($clave);
        }

        // Si esta pieza ya fue revisada, no es PESADO — deja que REVISADO la maneje.
        // (filtros intactos, sin cambios)
        $isRev = (int) ($rowPaso1['ISREV'] ?? 0);
        $pesoRv = $rowPaso1['PESORV'] ?? null;

        if ($isRev === 1 || ! empty($pesoRv)) {
            throw new RolloNoEncontradoException($clave);
        }

        $otPsd = $rowPaso1['OT_PSD'];

        // ── Paso 2: ORDENESTEJ — CANT, CANTENT, ESTATUS y OP (sin cambios)
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

        $cantEnt = (float) ($rowPaso2['CANTENT'] ?? 0);
        $cant = (float) ($rowPaso2['CANT'] ?? 0);
        $estatus = (int) ($rowPaso2['ESTATUS'] ?? 0);

        $op = (string) $rowPaso2['OP'];

        // ── Paso 3: P_ORDENESENC (sin cambios)
        $sqlPaso3 = "
        SELECT
            P.ARTICULO AS ARTICULO,
            P.CLIENTE AS CLIENTE,
            COALESCE(V.agente,'SIN AGENTE') AS AGENTE,
            P.PEDIDO AS PEDIDO,
            P.ORDEN AS OP,
            P.PARTIDA AS PEDIDOPART,
            P.\"CODIGO COLOR\" AS \"COD. COLOR\",
            P.COLOR AS COLOR,
            P.FECHA AS FECHA,
            P.ORDEN AS ORDEN,
            P.NESTATUS AS OE_ESTATUS,
            P.ESTATUS AS PROCESO,
            P.CVE_PED AS CVE_PED,
            P.CVE_ORDEN AS CVE_ORDEN
        FROM P_ORDENESENC('03') P
        LEFT JOIN p_vendxx('03') V ON V.id = P.AGENTE
        WHERE P.ORDEN = ?
        ";

        $row = $this->ejecutar($sqlPaso3, [$op], 'PESADO-PASO3', $codigoRaw, $clave);

        if (! $row) {
            throw new RolloNoEncontradoException($clave);
        }

        $row['ID'] = $rowPaso1['ID'];
        $row['ID_QR'] = str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT);
        $row['ORIGEN'] = 'PESADO';
        $row['CANT'] = $cant;
        $row['CANTENT'] = $cantEnt;
        $row['PESADO_ESTATUS'] = $estatus;
        $row['PESADO_COMPLETO'] = $cantEnt >= $cant && $estatus === 51;

        // ── Datos ampliados del Paso 1 (pieza tejida)
        $row['PIEZA'] = $rowPaso1['PIEZA'];
        $row['PESO_TEJIDO'] = $rowPaso1['PESO_TEJIDO'];
        $row['CVE_ART'] = $rowPaso1['CVE_ART'];
        $row['MAQUINA'] = $rowPaso1['MAQUINA'];
        $row['FECHA_PESADO'] = $rowPaso1['FECHA_PESADO'];
        $row['NOMBRE'] = $rowPaso1['NOMBRE'];
        $row['TEJIDO'] = $rowPaso1['TEJIDO'];
        $row['HILATURA'] = $rowPaso1['HILATURA'];

        return $row;
    }

    public function escanearRevisado(string $codigoRaw): array
    {
        $clave = $this->normalizarClave($codigoRaw);

        $sqlPaso1 = '
        SELECT
            PJ.ID AS ID,
            PJ.ISREV AS ISREV,
            PJ.PESORV AS PESORV,
            PJ.CVE_ORDEN AS CVE_ORDEN,
            PJ.CVE_ORDEN_OP AS CVE_ORDEN_OP,
            PJ.ID_FOLCDO_PL AS ID_FOLCDO_PL,
            PJ.CVE_ART AS CVE_ART,
            PJ.PZA AS PZA,
            PJ.PESOTJ AS PESOTJ,
            PJ.PESOSL AS PESOSL,
            PJ.ALMACEN AS ALMACEN,
            PJ.ID_FOL_INV AS ID_FOL_INV,
            PJ.FECHAYHORADELIV AS FECHAYHORADELIV,
            PJ.USDELIV AS USDELIV,
            PJ.ISDELIV AS ISDELIV,
            PJ.FECHAYHORAREV AS FECHA_REVISADO,
            PJ.OT_PSD AS ORDEN_TEJIDO,
            PJ.CLASIF_TO_CLR AS CLASIFICACION,
            PJ.CE AS MAQUINA,
            ART.NOMBRE AS ARTICULO,
            T.TEJIDO AS TEJIDO,
            C.COMPOSICION AS COMPOSICION,
            PA.NOM_EMP AS TEJEDOR,
            PA.NOM_EMPREV AS REVISADOR,
            ALM.DESCR AS ALMACEN_NOMBRE
        FROM PSDTABPZASTJ PJ
        LEFT JOIN ARTICULOS ART ON PJ.CVE_ART = ART.ID
        LEFT JOIN ARTICULOSH ARTH ON PJ.CVE_ART = ARTH.CVE_ART
        LEFT JOIN ARTICULOS ART2 ON ARTH.CVE_ART = ART2.ID
        LEFT JOIN TEJIDO T ON T.ID = COALESCE(ART.TEJ, ART2.TEJ)
        LEFT JOIN COMPOSICION C ON C.ID = ART.COMP
        LEFT JOIN PSDTABPZASTJAUX PA ON PA.ID = PJ.ID
        LEFT JOIN ALMACENES03 ALM ON ALM.CVE_ALM = PJ.ALMACEN
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

        // ⚠️ (comentario original sin cambios)

        $cveOrden = $rowPaso1['CVE_ORDEN'];

        $folioVentaDirecta = $rowPaso1['ID_FOLCDO_PL'] ?? null;
        $ordenSurte = $rowPaso1['CVE_ORDEN_OP'] ?? null;

        $esVentaDirecta = ! empty($folioVentaDirecta);
        $tieneSurtido = ! empty($ordenSurte);

        // Campos ampliados del Paso 1, reutilizables en los 4 returns.
        $datosAmpliados = [
            'PESO_REVISADO' => $rowPaso1['PESORV'],
            'FECHA_REVISADO' => $rowPaso1['FECHA_REVISADO'],
            'ORDEN_TEJIDO' => $rowPaso1['ORDEN_TEJIDO'],
            'CLASIFICACION' => $rowPaso1['CLASIFICACION'],
            'MAQUINA' => $rowPaso1['MAQUINA'],
            'ARTICULO' => $rowPaso1['ARTICULO'],
            'TEJIDO' => $rowPaso1['TEJIDO'],
            'COMPOSICION' => $rowPaso1['COMPOSICION'],
            'TEJEDOR' => $rowPaso1['TEJEDOR'],
            'REVISADOR' => $rowPaso1['REVISADOR'],
        ];

        // ══════════════════════════════════════════════════════════
        // CASO 1: Ya se vendió (venta directa).
        // ══════════════════════════════════════════════════════════
        if ($esVentaDirecta) {
            Log::info('ℹ️ INVENTARIO_REVISADO_ES_VENTA_DIRECTA', [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
                'folio_venta_directa' => $folioVentaDirecta,
                'isdeliv' => $rowPaso1['ISDELIV'] ?? null,
            ]);

            $datosVentaDirecta = $this->buscarDatosVentaDirecta((string) $folioVentaDirecta, $codigoRaw, $clave);

            if (! $datosVentaDirecta) {
                Log::warning('⚠️ INVENTARIO_REVISADO_VENTA_DIRECTA_SIN_DATOS_PTPLISTCDO', [
                    'codigo_qr' => $codigoRaw,
                    'clave' => $clave,
                    'folio_venta_directa' => $folioVentaDirecta,
                ]);

                $datosVentaDirecta = [];
            }

            // USDELIV (entrega en PSDTABPZASTJ) suele venir vacío en venta directa;
            // en ese caso usamos el usuario de laboratorio (USELAB) resuelto en PTPLISTCDO.
            $usuarioEntrega = $rowPaso1['USDELIV'] ?: ($datosVentaDirecta['USELAB_NOMBRE'] ?? null);

            return array_merge([
                'ID' => $rowPaso1['ID'],
                'ID_QR' => str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT),
                'CVE_ART' => $rowPaso1['CVE_ART'],
                'PIEZA' => $rowPaso1['PZA'],
                'PESO_TJ' => $rowPaso1['PESOTJ'],
                'PESO_SL' => $rowPaso1['PESOSL'],
                'PESO_REVISADO' => $rowPaso1['PESORV'],
                'CVE_ORDEN' => $cveOrden,
                'ORDEN_SURTE' => $ordenSurte,
                'ALMACEN' => $rowPaso1['ALMACEN_NOMBRE'] ?? $rowPaso1['ALMACEN'],
                'FOLIO_INVENTARIO' => $rowPaso1['ID_FOL_INV'],
                'FOLIO_VENTA' => $folioVentaDirecta,
                'ENTREGADO' => (int) ($rowPaso1['ISDELIV'] ?? 0) === 1,
                'FECHA_ENTREGA' => $rowPaso1['FECHAYHORADELIV'],
                'USUARIO_ENTREGA' => $usuarioEntrega,
                'ORIGEN' => 'REVISADO',
                'SUBTIPO' => 'VENTA_DIRECTA',
            ], $datosAmpliados, $datosVentaDirecta);
        }

        // Sin orden asociada y sin venta directa.
        if (empty($cveOrden)) {
            Log::info('ℹ️ INVENTARIO_REVISADO_SIN_CVE_ORDEN', [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
            ]);

            return array_merge([
                'ID' => $rowPaso1['ID'],
                'ID_QR' => str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT),
                'CVE_ART' => $rowPaso1['CVE_ART'],
                'PIEZA' => $rowPaso1['PZA'],
                'PESO_TJ' => $rowPaso1['PESOTJ'],
                'PESO_SL' => $rowPaso1['PESOSL'],
                'ALMACEN' => $rowPaso1['ALMACEN_NOMBRE'] ?? $rowPaso1['ALMACEN'],
                'FOLIO_INVENTARIO' => $rowPaso1['ID_FOL_INV'],
                'ORIGEN' => 'REVISADO',
                'SUBTIPO' => 'SIN_ORDEN',
            ], $datosAmpliados);
        }

        // ══════════════════════════════════════════════════════════
        // CASO 2: Ya tiene orden de surtido asignada.
        // (se checa ANTES que "sin orden", porque ordenSurte/CVE_ORDEN_OP
        // es independiente de CVE_ORDEN — un rollo puede tener CVE_ORDEN
        // vacío pero ya estar surtido a tintorería)
        // ══════════════════════════════════════════════════════════
        if ($tieneSurtido) {
            $rowSurtido = $this->buscarDatosOrden($ordenSurte, $codigoRaw, $clave, 'REVISADO-SURTIDO');

            if (! $rowSurtido) {
                Log::warning('⚠️ INVENTARIO_REVISADO_SURTIDO_SIN_DATOS_ORDEN', [
                    'codigo_qr' => $codigoRaw,
                    'clave' => $clave,
                    'cve_orden' => $cveOrden,
                    'orden_surte' => $ordenSurte,
                ]);

                $rowSurtido = [];
            }

            $oeEstatusSurtido = (int) ($rowSurtido['OE_ESTATUS'] ?? 0);

            $rowSurtido['ID'] = $rowPaso1['ID'];
            $rowSurtido['ID_QR'] = str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT);
            $rowSurtido['ORDEN_SURTE'] = $ordenSurte;
            $rowSurtido['ORIGEN'] = 'REVISADO';
            $rowSurtido['SUBTIPO'] = $oeEstatusSurtido === 4 ? 'CONTROL_CALIDAD' : 'SURTIDO';

            return array_merge($rowSurtido, $datosAmpliados);
        }

        // Sin orden asociada, sin venta directa y sin surtido.
        if (empty($cveOrden)) {
            Log::info('ℹ️ INVENTARIO_REVISADO_SIN_CVE_ORDEN', [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
            ]);

            return array_merge([
                'ID' => $rowPaso1['ID'],
                'ID_QR' => str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT),
                'CVE_ART' => $rowPaso1['CVE_ART'],
                'PIEZA' => $rowPaso1['PZA'],
                'PESO_TJ' => $rowPaso1['PESOTJ'],
                'PESO_SL' => $rowPaso1['PESOSL'],
                'ALMACEN' => $rowPaso1['ALMACEN_NOMBRE'] ?? $rowPaso1['ALMACEN'],
                'FOLIO_INVENTARIO' => $rowPaso1['ID_FOL_INV'],
                'ORIGEN' => 'REVISADO',
                'SUBTIPO' => 'SIN_ORDEN',
            ], $datosAmpliados);
        }

        // ══════════════════════════════════════════════════════════
        // CASO 3: Ni venta ni surtido — sigue en proceso normal.
        // ══════════════════════════════════════════════════════════
        $row = $this->buscarDatosOrden($cveOrden, $codigoRaw, $clave, 'REVISADO-PROCESO');

        if (! $row) {
            Log::warning('⚠️ INVENTARIO_REVISADO_PROCESO_SIN_DATOS_ORDEN', [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
                'cve_orden' => $cveOrden,
            ]);

            $row = [];
        }

        $row['ID'] = $rowPaso1['ID'];
        $row['ID_QR'] = str_pad((string) $rowPaso1['ID'], 10, '0', STR_PAD_LEFT);
        $row['ORIGEN'] = 'REVISADO';
        $row['SUBTIPO'] = 'PROCESO';

        return array_merge($row, $datosAmpliados);
    }

    /**
     * Busca los datos de una orden intentando primero P_PSDENC (más completo,
     * disponible cuando la orden ya cruzó a acabado) y cayendo a P_ORDENESENC
     * si aún no ha llegado (sigue en proceso).
     */
    private function buscarDatosOrden(int $cveOrden, string $codigoRaw, int $clave, string $etiquetaPaso): ?array
    {
        $sqlPsdenc = "
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
                IIF(OE.ESTATUS = 2, S.PROCESO, E.ESTATUS) AS PROCESO,
                OE.CANTIDAD AS \"CANTIDAD SOLICITADA\",
                OE.CANTENT AS \"CANTIDAD ENTREGADA\"
            FROM ORDENESENC OE
            INNER JOIN P_PSDENC('03') P ON P.CVE_ORDEN = OE.ID
            LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
            LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
            LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
            LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
            WHERE OE.ID = ?
        ";

        $row = $this->ejecutar($sqlPsdenc, [$cveOrden], "{$etiquetaPaso}-PSDENC", $codigoRaw, $clave);

        if ($row) {
            return $row;
        }

        $sqlOrdenesenc = "
        SELECT
            P.ARTICULO AS ARTICULO,
            P.CLIENTE AS CLIENTE,
            COALESCE(V.agente,'SIN AGENTE') AS AGENTE,
            P.PEDIDO AS PEDIDO,
            P.ORDEN AS OP,
            P.PARTIDA AS PEDIDOPART,
            P.\"CODIGO COLOR\" AS \"COD. COLOR\",
            P.COLOR AS COLOR,
            P.FECHA AS FECHA,
            P.ORDEN AS ORDEN,
            P.NESTATUS AS OE_ESTATUS,
            P.ESTATUS AS PROCESO
        FROM P_ORDENESENC('03') P
        LEFT JOIN p_vendxx('03') V ON V.id = P.AGENTE
        WHERE P.CVE_ORDEN = ?
    ";

        return $this->ejecutar($sqlOrdenesenc, [$cveOrden], "{$etiquetaPaso}-ORDENESENC", $codigoRaw, $clave);
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
                ) AS PRODUCTO,
                OE.CANTIDAD AS \"CANTIDAD SOLICITADA\",
                OE.CANTENT AS \"CANTIDAD ENTREGADA\"
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

        $oeEstatus = (int) ($row['OE_ESTATUS'] ?? 0);
        $row['ORIGEN'] = $oeEstatus === 61 ? 'FACTURACION' : 'ACABADO';

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
            $resultado = $this->firebird->getProductionConnection()->selectOne($sql, $bindings);

            // El driver de Firebird puede regresar stdClass en vez de array asociativo.
            return $resultado === null ? null : (array) $resultado;
        } catch (\Throwable $e) {
            Log::error("Error al escanear rollo ({$paso}) en Firebird: ".$e->getMessage(), [
                'codigo_qr' => $codigoRaw,
                'clave' => $clave,
            ]);

            throw $e;
        }
    }

    /**
     * Busca fecha de entrega y usuario de laboratorio en PTPLISTCDO
     * para una venta directa, relacionando PTPLISTCDO.ID_CDO con
     * PSDTABPZASTJ.ID_FOLCDO_PL.
     */
    private function buscarDatosVentaDirecta(string $folioVentaDirecta, string $codigoRaw, int $clave): ?array
    {
        $sql = '
    SELECT
        PL.FECHAYHORA AS FECHA_ENTREGA_CDO,
        PL.USELAB AS USELAB,
        U.NOMBRE AS USELAB_NOMBRE
    FROM PTPLISTCDO PL
    LEFT JOIN USUARIOS U ON U.CLAVE = PL.USELAB
    WHERE PL.ID_CDO = ?
    ';

        return $this->ejecutar($sql, [$folioVentaDirecta], 'REVISADO-VENTA-DIRECTA-PTPLISTCDO', $codigoRaw, $clave);
    }
}
