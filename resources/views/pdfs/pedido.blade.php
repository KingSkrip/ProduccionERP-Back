<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Pedido {{ $pedido['cve_ped'] ?? '' }}</title>
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

        /* ── HEADER ── */
        .header {
            background: #1a1a2e;
            color: #fff;
            padding: 20px 24px;
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
            font-size: 17px;
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
            font-size: 24px;
            font-weight: 700;
            color: #63b3ed;
            line-height: 1.1;
        }

        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 5px;
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

        /* ── INFO BAR ── */
        .info-bar {
            background: #f7fafc;
            border-bottom: 2px solid #e2e8f0;
            padding: 12px 24px;
        }

        .info-grid {
            display: table;
            width: 100%;
        }

        .info-cell {
            display: table-cell;
            padding-right: 16px;
            vertical-align: top;
        }

        .info-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #718096;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 11px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-sub {
            font-size: 9px;
            color: #a0aec0;
            margin-top: 1px;
        }

        /* ── BODY ── */
        .body {
            padding: 18px 24px 50px;
        }

        /* ── SECCIÓN ── */
        .section {
            margin-bottom: 18px;
        }

        .section-title {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #718096;
            border-left: 3px solid #4299e1;
            padding-left: 7px;
            margin-bottom: 8px;
        }

        .section-title.purple {
            border-left-color: #9f7aea;
        }

        /* ── TABLAS ── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        thead th {
            background: #edf2f7;
            padding: 6px 10px;
            text-align: left;
            font-size: 8px;
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
            padding: 6px 10px;
            color: #2d3748;
        }

        tbody td.r {
            text-align: right;
            font-weight: 600;
        }

        tfoot td {
            background: #edf2f7;
            border-top: 2px solid #cbd5e0;
            padding: 6px 10px;
            font-weight: 700;
            font-size: 10px;
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

        /* ── OBSERVACIONES ── */
        .obs {
            background: #fffbeb;
            border: 1px solid #fbd38d;
            border-left: 3px solid #f6ad55;
            border-radius: 3px;
            padding: 7px 12px;
            margin-bottom: 14px;
            font-size: 10px;
            color: #744210;
        }

        .obs-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        /* ── SIN DATOS ── */
        .empty {
            text-align: center;
            padding: 14px;
            color: #a0aec0;
            font-size: 10px;
        }

        /* ── FOOTER ── */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #f7fafc;
            border-top: 1px solid #e2e8f0;
            padding: 7px 24px;
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
    </style>
</head>

<body>

    <!-- ══ HEADER ══ -->
    <div class="header">
        <div class="header-row">
            <div class="header-left">
                <div class="company-name">Comercializadora Fibrasan</div>
                <div class="company-sub">Estado de Pedido</div>
            </div>
            <div class="header-right">
                <div class="doc-label">Pedido</div>
                <div class="doc-number">#{{ $pedido['cve_ped'] ?? '' }}</div>
                @php $status = trim($pedido['status'] ?? ''); @endphp
                @if ($status === 'Completo')
                    <span class="status-pill completo">Completo</span>
                @elseif($status === 'Parcial')
                    <span class="status-pill parcial">Parcial</span>
                @else
                    <span class="status-pill sindef">{{ $status ?: 'Sin definir' }}</span>
                @endif
            </div>
        </div>
    </div>

    <!-- ══ INFO BAR ══ -->
    <div class="info-bar">
        <div class="info-grid">
            <div class="info-cell">
                <div class="info-label">Cliente</div>
                <div class="info-value">{{ $pedido['nombre'] ?? '—' }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Referencia</div>
                <div class="info-value">{{ $pedido['referencia'] ?: '—' }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Condición</div>
                <div class="info-value">{{ $pedido['condicion'] ?? '—' }}</div>
                @if (($pedido['dias_credito'] ?? 0) > 0)
                    <div class="info-sub">({{ $pedido['dias_credito'] }} días)</div>
                @endif
            </div>
            <div class="info-cell">
                <div class="info-label">Agente</div>
                <div class="info-value">{{ $pedido['agente'] ?? '—' }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Elaboración</div>
                <div class="info-value">{{ $pedido['fecha_elab'] ?? '—' }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Entrega</div>
                <div class="info-value">{{ $pedido['fecha_entrega'] ?? '—' }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Pago</div>
                <div class="info-value">{{ $pedido['fecha_pago'] ?? '—' }}</div>
            </div>
        </div>
    </div>

    <!-- ══ BODY ══ -->
    <div class="body">

        {{-- Observaciones --}}
        @if (!empty($pedido['observaciones']))
            <div class="obs">
                <div class="obs-label">Observaciones</div>
                {{ $pedido['observaciones'] }}
            </div>
        @endif

        {{-- ── ARTÍCULOS ── --}}
        <div class="section">
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
                                <td class="r">{{ number_format((float) ($art['CANTIDAD'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="r">Total kg</td>
                            <td class="r">
                                @php
                                    $totalArt = array_sum(
                                        array_map(function ($a) {
                                            $o = is_array($a) ? $a : (array) $a;
                                            return (float) ($o['CANTIDAD'] ?? 0);
                                        }, $pedido['articulos']),
                                    );
                                @endphp
                                {{ number_format($totalArt, 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            @else
                <div class="empty">Sin artículos registrados</div>
            @endif
        </div>

        {{-- ── CARDIGANS ── --}}
        @if (!empty($pedido['cardigans']) && count($pedido['cardigans']) > 0)
            <div class="section">
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
                                <td class="r">{{ number_format((float) ($card['CANTIDAD'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="r">Total kg</td>
                            <td class="r">
                                {{ number_format(array_sum(array_map(fn($c) => (float) ($c['CANTIDAD'] ?? ($c->CANTIDAD ?? 0)), $pedido['cardigans'])), 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

    </div>

    <!-- ══ FOOTER ══ -->
    <div class="footer">
        <div class="footer-l">Comercializadora Fibrasan &mdash; Documento generado el {{ $fecha_generacion ?? '' }}
        </div>
        <div class="footer-r">Pedido #{{ $pedido['cve_ped'] ?? '' }}</div>
    </div>

</body>

</html>
