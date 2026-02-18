<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Estados de Cuenta</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 6px;
            text-align: right;
        }

        th {
            background: #f5f5f5;
        }

        .left {
            text-align: left;
        }

        .resumen {
            display: flex;
            gap: 12px;
            margin: 12px 0;
        }

        .card {
            flex: 1;
            border-radius: 6px;
            padding: 10px 14px;
        }

        .card-blue {
            background: #dbeafe;
        }

        .card-green {
            background: #dcfce7;
        }

        .card-red {
            background: #fee2e2;
        }

        .card-label {
            font-size: 10px;
            color: #555;
            margin-bottom: 4px;
        }

        .card-value {
            font-size: 16px;
            font-weight: bold;
        }

        .card-blue .card-value {
            color: #1d4ed8;
        }

        .card-green .card-value {
            color: #15803d;
        }

        .card-red .card-value {
            color: #b91c1c;
        }

        tfoot td {
            font-weight: bold;
            background: #f5f5f5;
        }
    </style>
</head>

<body>

    <h2>Estados de Cuenta</h2>
    <p><strong>Cliente:</strong> {{ $cliente }}</p>
    <p><strong>Fecha generación:</strong> {{ $fecha_generacion }}</p>

    {{-- Resumen totales --}}
    @php
        $totalCargos = collect($documentos)->sum('CARGOS');
        $totalAbonos = collect($documentos)->sum('ABONOS');
        $totalSaldos = collect($documentos)->sum('SALDOS');
    @endphp

    <table>
        <thead>
            <tr>
                <th class="left">Documento</th>
                <th>Fecha</th>
                <th>Vencimiento</th>
                <th>Cargos</th>
                <th>Abonos</th>
                <th>Saldo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documentos as $doc)
                <tr>
                    <td class="left">{{ $doc->DOCUMENTO }}</td>
                    <td>{{ \Carbon\Carbon::parse($doc->FECHA_APLI)->format('d/m/Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($doc->FECHA_VENC)->format('d/m/Y') }}</td>
                    <td>${{ number_format($doc->CARGOS, 2) }}</td>
                    <td>${{ number_format($doc->ABONOS, 2) }}</td>
                    <td>${{ number_format($doc->SALDOS, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="left" colspan="3">Totales</td>
                <td>${{ number_format($totalCargos, 2) }}</td>
                <td>${{ number_format($totalAbonos, 2) }}</td>
                <td>${{ number_format($totalSaldos, 2) }}</td>
            </tr>
        </tfoot>
    </table>

</body>

</html>
