<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Estados de Cuenta</title>
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

        .cliente-block:last-child {
            page-break-after: avoid;
        }

        /* ══ HEADER ══ */
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

        .cliente-meta {
            font-size: 9px;
            color: #718096;
            margin-top: 2px;
        }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 700;
        }

        .badge-blue {
            background: #bee3f8;
            color: #2b6cb0;
        }

        .badge-green {
            background: #c6f6d5;
            color: #276749;
        }

        .badge-red {
            background: #fed7d7;
            color: #9b2c2c;
        }

        .badge-gray {
            background: #e2e8f0;
            color: #4a5568;
        }

        /* ══ RESUMEN CARDS ══ */
        .resumen-bar {
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 24px;
            display: table;
            width: 100%;
        }

        .resumen-cell {
            display: table-cell;
            padding-right: 20px;
            vertical-align: middle;
            text-align: center;
        }

        .resumen-cell:last-child {
            padding-right: 0;
        }

        .resumen-label {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #a0aec0;
            margin-bottom: 2px;
        }

        .resumen-value {
            font-size: 13px;
            font-weight: 700;
        }

        .color-blue {
            color: #2b6cb0;
        }

        .color-green {
            color: #276749;
        }

        .color-red {
            color: #9b2c2c;
        }

        .color-gray {
            color: #4a5568;
        }

        /* ══ CUERPO ══ */
        .body {
            padding: 14px 24px 60px;
        }

        /* ══ TABLA DE DOCUMENTOS ══ */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        thead th {
            background: #edf2f7;
            padding: 6px 8px;
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
            padding: 6px 8px;
            color: #2d3748;
        }

        tbody td.r {
            text-align: right;
            font-weight: 600;
        }

        tbody td.vencido {
            color: #c53030;
            font-weight: 700;
        }

        tfoot td {
            background: #edf2f7;
            border-top: 2px solid #cbd5e0;
            padding: 6px 8px;
            font-weight: 700;
            font-size: 9px;
        }

        tfoot td.r {
            text-align: right;
        }

        tfoot td.r.blue {
            color: #2b6cb0;
        }

        tfoot td.r.green {
            color: #276749;
        }

        tfoot td.r.red {
            color: #c53030;
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

        .pill {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 20px;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .pill-pagado {
            background: #c6f6d5;
            color: #276749;
        }

        .pill-vencido {
            background: #fed7d7;
            color: #9b2c2c;
        }

        .pill-pendiente {
            background: #fefcbf;
            color: #975a16;
        }
    </style>
</head>

<body>

    {{-- ══ FOOTER FIJO ══ --}}
    <div class="footer">
        <div class="footer-l">
            Comercializadora Fibrasan &mdash; Generado el {{ $fecha_generacion ?? '' }}
        </div>
        <div class="footer-r">
            @php
                $totalDocs = 0;
                $totalClientes = 0;
                foreach ($por_cliente as $docs) {
                    $totalDocs += $docs->count();
                    $totalClientes++;
                }
            @endphp
            {{ $totalDocs }} documento{{ $totalDocs !== 1 ? 's' : '' }}
            &bull;
            {{ $totalClientes }} cliente{{ $totalClientes !== 1 ? 's' : '' }}
        </div>
    </div>

    {{-- ══ UN BLOQUE POR CLIENTE ══ --}}
    @foreach ($por_cliente as $clave => $documentos)
        @php
            $primer = $documentos->first();
            $nombre = trim($primer->NOMBRE ?? '—');
            $rfc = trim($primer->RFC ?? '');
            $hoy = \Carbon\Carbon::now();

            $totalCargos = $documentos->sum('CARGOS');
            $totalAbonos = $documentos->sum('ABONOS');
            $totalSaldo = $documentos->sum('SALDOS');
            $totalDocs = $documentos->count();

            $vencidos = $documentos
                ->filter(function ($d) use ($hoy) {
                    return $d->SALDOS > 0 && !empty($d->FECHA_VENC) && \Carbon\Carbon::parse($d->FECHA_VENC)->lt($hoy);
                })
                ->count();

            $pagados = $documentos->filter(fn($d) => $d->SALDOS <= 0)->count();
            $pendientes = $totalDocs - $vencidos - $pagados;
        @endphp

        <div class="cliente-block">

            {{-- ── HEADER ── --}}
            <div class="header">
                <div class="header-row">
                    <div class="header-left">
                        <div class="company-name">Comercializadora Fibrasan</div>
                        <div class="company-sub">Estado de Cuenta por Cliente</div>
                    </div>
                    <div class="header-right">
                        <div class="doc-label">Documento</div>
                        <div class="doc-number">Estado de Cuenta</div>
                        <div class="doc-sub">{{ $fecha_generacion ?? '' }}</div>
                    </div>
                </div>
            </div>

            {{-- ── CLIENTE BAR ── --}}
            <div class="cliente-bar">
                <div class="cliente-bar-left">
                    <div class="cliente-nombre">{{ $nombre }}</div>
                    <div class="cliente-meta">
                        Clave: {{ $clave }}
                        @if ($rfc)
                            &nbsp;&bull;&nbsp; RFC: {{ $rfc }}
                        @endif
                    </div>
                </div>
                <div class="cliente-bar-right">
                    <span class="badge badge-blue">{{ $totalDocs }} doc{{ $totalDocs !== 1 ? 's' : '' }}</span>
                    @if ($pagados > 0)
                        <span class="badge badge-green">{{ $pagados }}
                            pagado{{ $pagados !== 1 ? 's' : '' }}</span>
                    @endif
                    @if ($pendientes > 0)
                        <span class="badge badge-gray">{{ $pendientes }}
                            pendiente{{ $pendientes !== 1 ? 's' : '' }}</span>
                    @endif
                    @if ($vencidos > 0)
                        <span class="badge badge-red">{{ $vencidos }}
                            vencido{{ $vencidos !== 1 ? 's' : '' }}</span>
                    @endif
                </div>
            </div>

            {{-- ── RESUMEN FINANCIERO ── --}}
            <div class="resumen-bar">
                <div class="resumen-cell">
                    <div class="resumen-label">Cargos totales</div>
                    <div class="resumen-value color-blue">${{ number_format($totalCargos, 2) }}</div>
                </div>
                <div class="resumen-cell">
                    <div class="resumen-label">Abonos totales</div>
                    <div class="resumen-value color-green">${{ number_format($totalAbonos, 2) }}</div>
                </div>
                <div class="resumen-cell">
                    <div class="resumen-label">Saldo pendiente</div>
                    <div class="resumen-value {{ $totalSaldo > 0 ? 'color-red' : 'color-green' }}">
                        ${{ number_format($totalSaldo, 2) }}
                    </div>
                </div>
                <div class="resumen-cell">
                    <div class="resumen-label">Documentos</div>
                    <div class="resumen-value color-gray">{{ $totalDocs }}</div>
                </div>
                @if ($vencidos > 0)
                    <div class="resumen-cell">
                        <div class="resumen-label">Vencidos</div>
                        <div class="resumen-value color-red">{{ $vencidos }}</div>
                    </div>
                @endif
            </div>

            {{-- ── TABLA DE DOCUMENTOS ── --}}
            <div class="body">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>No. Documento</th>
                            <th>Fecha Aplic.</th>
                            <th>Vencimiento</th>
                            <th>Estado</th>
                            <th class="r">Cargos</th>
                            <th class="r">Abonos</th>
                            <th class="r">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documentos->sortBy('FECHA_APLI') as $i => $doc)
                            @php
                                $esPagado = $doc->SALDOS <= 0;
                                $esVencido =
                                    !$esPagado &&
                                    !empty($doc->FECHA_VENC) &&
                                    \Carbon\Carbon::parse($doc->FECHA_VENC)->lt($hoy);
                            @endphp
                            <tr>
                                <td style="color:#a0aec0;">{{ $i + 1 }}</td>
                                <td><strong>{{ trim($doc->DOCUMENTO) }}</strong></td>
                                <td>
                                    {{ $doc->FECHA_APLI ? \Carbon\Carbon::parse($doc->FECHA_APLI)->format('d/m/Y') : '—' }}
                                </td>
                                <td class="{{ $esVencido ? 'vencido' : '' }}">
                                    {{ $doc->FECHA_VENC ? \Carbon\Carbon::parse($doc->FECHA_VENC)->format('d/m/Y') : '—' }}
                                </td>
                                <td>
                                    @if ($esPagado)
                                        <span class="pill pill-pagado">Pagado</span>
                                    @elseif ($esVencido)
                                        <span class="pill pill-vencido">Vencido</span>
                                    @else
                                        <span class="pill pill-pendiente">Pendiente</span>
                                    @endif
                                </td>
                                <td class="r">${{ number_format((float) $doc->CARGOS, 2) }}</td>
                                <td class="r">${{ number_format((float) $doc->ABONOS, 2) }}</td>
                                <td class="r {{ $doc->SALDOS > 0 ? 'vencido' : '' }}">
                                    ${{ number_format((float) $doc->SALDOS, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5">Totales &mdash; {{ $nombre }}</td>
                            <td class="r blue">${{ number_format($totalCargos, 2) }}</td>
                            <td class="r green">${{ number_format($totalAbonos, 2) }}</td>
                            <td class="r red">${{ number_format($totalSaldo, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- ── TOTALES DEL CLIENTE ── --}}
            <div class="cliente-totales">
                <div class="totales-label">
                    Resumen &mdash; {{ $nombre }}
                </div>
                <div class="totales-values">
                    {{-- <span>
                        Cargos: <strong class="color-blue">${{ number_format($totalCargos, 2) }}</strong>
                    </span> --}}
                    <span>
                        Abonos: <strong class="color-green">${{ number_format($totalAbonos, 2) }}</strong>
                    </span>
                    <span>
                        Saldo:
                        <strong class="{{ $totalSaldo > 0 ? 'color-red' : 'color-green' }}">
                            ${{ number_format($totalSaldo, 2) }}
                        </strong>
                    </span>
                    @if ($vencidos > 0)
                        <span>
                            Vencidos: <strong class="color-red">{{ $vencidos }}</strong>
                        </span>
                    @endif
                </div>
            </div>

        </div>{{-- /cliente-block --}}
    @endforeach

</body>

</html>
