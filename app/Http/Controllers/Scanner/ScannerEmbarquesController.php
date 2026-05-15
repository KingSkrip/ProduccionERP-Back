<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Events\Scanner\ScanEmbarqueCreado;
use Illuminate\Http\Request;
use App\Services\FirebirdConnectionService;

class ScannerEmbarquesController extends Controller
{
    protected FirebirdConnectionService $firebird;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }


    public function index()
    {
        $connection = $this->firebird->getProductionConnection();
        $scans = $connection
            ->table('INVFISVSTEOPT')
            ->orderByDesc('FECHAYHORA')
            ->limit(200)
            ->get(['CODIGO', 'CODIGOENT', 'FECHAYHORA', 'PROCESADO']);

        return response()->json(['data' => $scans]);
    }


    public function scan(Request $request)
    {
        $codigoOriginal = trim($request->barcode);
        $codigoLimpio   = str_replace('AC-', '', $codigoOriginal);
        $codigoCeros    = str_pad($codigoLimpio, 10, '0', STR_PAD_LEFT);
        $fechaYHora     = now()->toDateTimeString();

        $connection = $this->firebird->getProductionConnection();
        $connection
            ->table('INVFISVSTEOPT')
            ->insert([
                'CODIGO'     => $codigoCeros,
                'CODIGOENT'  => (int) $codigoLimpio,
                'FECHAYHORA' => $fechaYHora,
                'PROCESADO'  => 0
            ]);

        // Disparar evento WebSocket
        broadcast(new ScanEmbarqueCreado(
            codigo: $codigoCeros,
            codigoEnt: (int) $codigoLimpio,
            fechaYHora: $fechaYHora,
            procesado: 0
        ));

        return response()->json([
            'ok'     => true,
            'codigo' => $codigoCeros
        ]);
    }
}
