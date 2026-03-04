<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Pedidos múltiples</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1a1a2e;
            background: #fff;
        }

        /* ══ PAGE BREAK entre clientes ══ */
        .cliente-block {
            page-break-after: always;
        }

        /* Último bloque no necesita page-break */
        .cliente-block:last-child {
            page-break-after: avoid;
        }

        /* ══ HEADER DOCUMENTO (portada por cliente) ══ */
        .header {
            background: #1a1a2e;
            color: #fff;
            padding: 18px 24px;
        }

        .header-row {
            display: table;
            width: 100%;
        }

        .header-left,
        .header-right {
            display: table-cell;
            vertical-align: top;
        }

        .header-right {
            text-align: right;
        }

        .company-name {
            font-size: 16px;
            font-weight: 700;
        }

        .company-sub {
            font-size: 9px;
            color: #a0aec0;
            margin-top: 2px;
        }

        .doc-label {
            font-size: 9px;
            color: #a0aec0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .doc-number {
            font-size: 20px;
            font-weight: 700;
            color: #63b3ed;
            line-height: 1.2;
        }

        .doc-sub {
            font-size: 9px;
            color: #a0aec0;
            margin-top: 3px;
        }

        /* ══ CLIENTE BAR ══ */
        .cliente-bar {
            background: #ebf8ff;
            border-bottom: 3px solid #4299e1;
            padding: 10px 24px;
            display: table;
            width: 100%;
        }

        .cliente-bar-left,
        .cliente-bar-right {
            display: table-cell;
            vertical-align: middle;
        }

        .cliente-bar-right {
            text-align: right;
        }

        .cliente-nombre {
            font-size: 14px;
            font-weight: 700;
            color: #2b6cb0;
        }

        .cliente-clave {
            font-size: 9px;
            color: #718096;
            margin-top: 2px;
        }

        .cliente-resumen {
            font-size: 9px;
            color: #4a5568;
        }

        .cliente-resumen span {
            margin-left: 10px;
        }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 700;
        }

        .badge-green {
            background: #c6f6d5;
            color: #276749;
        }

        .badge-amber {
            background: #fefcbf;
            color: #975a16;
        }

        .badge-gray {
            background: #e2e8f0;
            color: #4a5568;
        }

        .badge-blue {
            background: #bee3f8;
            color: #2b6cb0;
        }

        /* ══ CUERPO ══ */
        .body {
            padding: 14px 24px 60px;
        }

        /* ══ BLOQUE DE PEDIDO INDIVIDUAL ══ */
        .pedido-card {
            margin-bottom: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        /* Cabecera del pedido */
        .pedido-header {
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 8px 14px;
            display: table;
            width: 100%;
        }

        .pedido-header-left,
        .pedido-header-right {
            display: table-cell;
            vertical-align: middle;
        }

        .pedido-header-right {
            text-align: right;
        }

        .pedido-num {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .pedido-ref {
            font-size: 9px;
            color: #a0aec0;
            margin-top: 1px;
        }

        /* Info bar interna del pedido */
        .pedido-info {
            background: #fff;
            padding: 8px 14px;
            border-bottom: 1px solid #edf2f7;
        }

        .pedido-info-grid {
            display: table;
            width: 100%;
        }

        .pedido-info-cell {
            display: table-cell;
            padding-right: 14px;
            vertical-align: top;
        }

        .info-label {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #a0aec0;
            margin-bottom: 1px;
        }

        .info-value {
            font-size: 10px;
            font-weight: 600;
            color: #2d3748;
        }

        /* Observaciones */
        .obs {
            background: #fffbeb;
            border-left: 3px solid #f6ad55;
            padding: 5px 12px;
            margin: 8px 14px;
            font-size: 9px;
            color: #744210;
        }

        .obs-label {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        /* ══ TABLAS ══ */
        .table-wrap {
            padding: 8px 14px 10px;
        }

        .section-title {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #718096;
            border-left: 3px solid #4299e1;
            padding-left: 6px;
            margin-bottom: 6px;
        }

        .section-title.purple {
            border-left-color: #9f7aea;
            margin-top: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        thead th {
            background: #edf2f7;
            padding: 5px 8px;
            text-align: left;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #4a5568;
            border-bottom: 1px solid #e2e8f0;
        }

        thead th.r {
            text-align: right;
        }

        tbody tr {
            border-bottom: 1px solid #edf2f7;
        }

        tbody tr:nth-child(even) {
            background: #f7fafc;
        }

        tbody td {
            padding: 5px 8px;
            color: #2d3748;
        }

        tbody td.r {
            text-align: right;
            font-weight: 600;
        }

        tfoot td {
            background: #edf2f7;
            border-top: 2px solid #cbd5e0;
            padding: 5px 8px;
            font-weight: 700;
            font-size: 9px;
        }

        tfoot td.r {
            text-align: right;
            color: #3182ce;
        }

        /* tabla cardigans */
        .t-purple thead th {
            background: #faf5ff;
            color: #6b46c1;
            border-bottom: 1px solid #e9d8fd;
        }

        .t-purple tbody tr {
            border-bottom: 1px solid #e9d8fd;
        }

        .t-purple tbody tr:nth-child(even) {
            background: #faf5ff;
        }

        .t-purple tfoot td {
            background: #faf5ff;
            border-top: 2px solid #d6bcfa;
        }

        .t-purple tfoot td.r {
            color: #6b46c1;
        }

        /* ══ TOTALES CLIENTE ══ */
        .cliente-totales {
            background: #ebf8ff;
            border-top: 2px solid #4299e1;
            padding: 8px 24px;
            display: table;
            width: 100%;
        }

        .totales-label {
            display: table-cell;
            font-size: 10px;
            font-weight: 700;
            color: #2b6cb0;
            vertical-align: middle;
        }

        .totales-values {
            display: table-cell;
            text-align: right;
            vertical-align: middle;
        }

        .totales-values span {
            display: inline-block;
            margin-left: 16px;
            font-size: 9px;
            color: #4a5568;
        }

        .totales-values strong {
            font-size: 11px;
            color: #1a1a2e;
        }

        .kg-total {
            font-size: 13px;
            font-weight: 700;
            color: #2b6cb0;
        }

        /* ══ FOOTER fijo ══ */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #f7fafc;
            border-top: 1px solid #e2e8f0;
            padding: 6px 24px;
            display: table;
            width: 100%;
        }

        .footer-l {
            display: table-cell;
            font-size: 8px;
            color: #a0aec0;
            vertical-align: middle;
        }

        .footer-r {
            display: table-cell;
            font-size: 8px;
            color: #a0aec0;
            text-align: right;
            vertical-align: middle;
        }

        .empty {
            text-align: center;
            padding: 10px;
            color: #a0aec0;
            font-size: 9px;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .completo {
            background: #c6f6d5;
            color: #276749;
        }

        .parcial {
            background: #fefcbf;
            color: #975a16;
        }

        .sindef {
            background: #e2e8f0;
            color: #4a5568;
        }
    </style>
</head>

<body>

    {{-- ══ FOOTER FIJO (visible en todas las páginas) ══ --}}
    <div class="footer">
        <div class="footer-l">
            Comercializadora Fibrasan &mdash; Generado el {{ $fecha_generacion ?? '' }}
        </div>
        <div class="footer-r">
            {{ $pedidos->count() }} pedido{{ $pedidos->count() !== 1 ? 's' : '' }} &bull;
            {{ $pedidos->unique('cve_clie')->count() }}
            cliente{{ $pedidos->unique('cve_clie')->count() !== 1 ? 's' : '' }}
        </div>
    </div>

    @php
        // Agrupar pedidos por cliente
        $porCliente = $pedidos->groupBy('cve_clie');
    @endphp

    {{-- ══ UN BLOQUE POR CLIENTE ══ --}}
    @foreach ($porCliente as $cveClie => $pedidosCliente)
        @php
            $primerPedido = $pedidosCliente->first();
            $nombreCliente = $primerPedido['nombre'] ?? '—';
            $totalPedidos = $pedidosCliente->count();
            $completos = $pedidosCliente->filter(fn($p) => trim($p['status'] ?? '') === 'Completo')->count();
            $parciales = $pedidosCliente->filter(fn($p) => trim($p['status'] ?? '') === 'Parcial')->count();
            $sinDef = $totalPedidos - $completos - $parciales;

            $totalKgCliente = $pedidosCliente->reduce(function ($carry, $p) {
                $kgArt = array_sum(array_map(fn($a) => (float) ($a['CANTIDAD'] ?? 0), $p['articulos'] ?? []));
                $kgCard = array_sum(array_map(fn($c) => (float) ($c['CANTIDAD'] ?? 0), $p['cardigans'] ?? []));
                return $carry + $kgArt + $kgCard;
            }, 0);
        @endphp

        <div class="cliente-block">

            {{-- ── HEADER DOCUMENTO ── --}}
            <div class="header">
                <div class="header-row">
                    <div class="header-left">
                        <div class="company-name">Comercializadora Fibrasan</div>
                        <div class="company-sub">Reporte de Pedidos por Cliente</div>
                    </div>
                    <div class="header-right">
                        <div class="doc-label">Documento</div>
                        <div class="doc-number">Pedidos</div>
                        <div class="doc-sub">{{ $fecha_generacion ?? '' }}</div>
                    </div>
                </div>
            </div>

            {{-- ── CLIENTE BAR ── --}}
            <div class="cliente-bar">
                <div class="cliente-bar-left">
                    <div class="cliente-nombre">{{ $nombreCliente }}</div>
                    <div class="cliente-clave">Clave: {{ $cveClie }}</div>
                </div>
                <div class="cliente-bar-right">
                    <div class="cliente-resumen">
                        <span class="badge badge-blue">{{ $totalPedidos }}
                            pedido{{ $totalPedidos !== 1 ? 's' : '' }}</span>
                        <span class="badge badge-green">{{ $completos }}
                            entregado{{ $completos !== 1 ? 's' : '' }}</span>
                        <span class="badge badge-amber">{{ $parciales }} en proceso</span>
                        @if ($sinDef > 0)
                            <span class="badge badge-gray">{{ $sinDef }} sin autorizar</span>
                        @endif
                        <span class="badge badge-blue" style="margin-left:10px; font-size:9px;">
                            {{ number_format($totalKgCliente, 0) }} kg totales
                        </span>
                    </div>
                </div>
            </div>

            {{-- ── PEDIDOS DEL CLIENTE ── --}}
            <div class="body">

                @foreach ($pedidosCliente as $pedido)
                    @php
                        $status = trim($pedido['status'] ?? '');
                        $kgArt = array_sum(
                            array_map(fn($a) => (float) ($a['CANTIDAD'] ?? 0), $pedido['articulos'] ?? []),
                        );
                        $kgCard = array_sum(
                            array_map(fn($c) => (float) ($c['CANTIDAD'] ?? 0), $pedido['cardigans'] ?? []),
                        );
                        $kgTotal = $kgArt + $kgCard;
                    @endphp

                    <div class="pedido-card">

                        {{-- Cabecera del pedido --}}
                        <div class="pedido-header">
                            <div class="pedido-header-left">
                                <div class="pedido-num">Pedido #{{ $pedido['cve_ped'] ?? '—' }}</div>
                                @if (!empty($pedido['referencia']))
                                    <div class="pedido-ref">Ref: {{ $pedido['referencia'] }}</div>
                                @endif
                            </div>
                            <div class="pedido-header-right">
                                @if ($status === 'Completo')
                                    <span class="status-pill completo">Entregado</span>
                                @elseif ($status === 'Parcial')
                                    <span class="status-pill parcial">En proceso</span>
                                @else
                                    <span class="status-pill sindef">{{ $status ?: 'Sin autorizar' }}</span>
                                @endif
                                <span style="margin-left:8px; font-size:11px; font-weight:700; color:#2b6cb0;">
                                    {{ number_format($kgTotal, 2) }} kg
                                </span>
                            </div>
                        </div>

                        {{-- Info del pedido --}}
                        <div class="pedido-info">
                            <div class="pedido-info-grid">
                                <div class="pedido-info-cell">
                                    <div class="info-label">Condición</div>
                                    <div class="info-value">{{ $pedido['condicion'] ?: 'Sin definir' }}</div>
                                </div>
                                <div class="pedido-info-cell">
                                    <div class="info-label">Agente</div>
                                    <div class="info-value">{{ $pedido['agente'] ?? '—' }}</div>
                                </div>
                                <div class="pedido-info-cell">
                                    <div class="info-label">Elaboración</div>
                                    <div class="info-value">{{ $pedido['fecha_elab'] ?? '—' }}</div>
                                </div>
                                <div class="pedido-info-cell">
                                    <div class="info-label">Entrega</div>
                                    <div class="info-value">{{ $pedido['fecha_entrega'] ?? '—' }}</div>
                                </div>
                                <div class="pedido-info-cell">
                                    <div class="info-label">Pago</div>
                                    <div class="info-value">{{ $pedido['fecha_pago'] ?? '—' }}</div>
                                </div>
                            </div>
                        </div>

                        {{-- Observaciones --}}
                        @if (!empty($pedido['observaciones']))
                            <div class="obs">
                                <div class="obs-label">Observaciones</div>
                                {{ $pedido['observaciones'] }}
                            </div>
                        @endif

                        {{-- Artículos y cardigans --}}
                        <div class="table-wrap">

                            {{-- Artículos --}}
                            <div class="section-title">Artículos</div>
                            @if (!empty($pedido['articulos']) && count($pedido['articulos']) > 0)
                                <table>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Artículo</th>
                                            <th class="r">Cantidad (kg)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($pedido['articulos'] as $i => $a)
                                            @php $art = is_array($a) ? $a : (array) $a; @endphp
                                            <tr>
                                                <td style="color:#a0aec0;">{{ $i + 1 }}</td>
                                                <td>{{ $art['ARTICULO'] ?? '—' }}</td>
                                                <td class="r">
                                                    {{ number_format((float) ($art['CANTIDAD'] ?? 0), 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" class="r">Total artículos</td>
                                            <td class="r">{{ number_format($kgArt, 2) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            @else
                                <div class="empty">Sin artículos registrados</div>
                            @endif

                            {{-- Cardigans --}}
                            @if (!empty($pedido['cardigans']) && count($pedido['cardigans']) > 0)
                                <div class="section-title purple">Cardigans</div>
                                <table class="t-purple">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Descripción</th>
                                            <th class="r">Cantidad (kg)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($pedido['cardigans'] as $i => $c)
                                            @php $card = is_array($c) ? $c : (array) $c; @endphp
                                            <tr>
                                                <td style="color:#a0aec0;">{{ $i + 1 }}</td>
                                                <td>{{ $card['DESCRIPCION'] ?? '—' }}</td>
                                                <td class="r">
                                                    {{ number_format((float) ($card['CANTIDAD'] ?? 0), 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" class="r">Total cardigans</td>
                                            <td class="r">{{ number_format($kgCard, 2) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            @endif

                        </div>{{-- /table-wrap --}}
                    </div>{{-- /pedido-card --}}
                @endforeach

            </div>{{-- /body --}}

            {{-- ── TOTALES DEL CLIENTE ── --}}
            <div class="cliente-totales">
                <div class="totales-label">
                    Resumen &mdash; {{ $nombreCliente }}
                </div>
                <div class="totales-values">
                    <span>Pedidos: <strong>{{ $totalPedidos }}</strong></span>
                    <span style="color:#276749;">Entregados: <strong>{{ $completos }}</strong></span>
                    <span style="color:#975a16;">En proceso: <strong>{{ $parciales }}</strong></span>
                    @if ($sinDef > 0)
                        <span style="color:#718096;">Sin autorizar: <strong>{{ $sinDef }}</strong></span>
                    @endif
                    <span>&nbsp;&nbsp;<span class="kg-total">{{ number_format($totalKgCliente, 0) }} kg</span></span>
                </div>
            </div>

        </div>{{-- /cliente-block --}}
    @endforeach

</body>

</html>
