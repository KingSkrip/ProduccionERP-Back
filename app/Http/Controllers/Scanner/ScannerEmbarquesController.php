<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FirebirdConnectionService;

class ScannerEmbarquesController extends Controller
{

    protected FirebirdConnectionService $firebird;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }

    public function scan(Request $request)
    {
        $codigoOriginal = trim($request->barcode);
        $codigoLimpio = str_replace('AC-', '', $codigoOriginal);
        $codigoCeros = str_pad($codigoLimpio, 10, '0', STR_PAD_LEFT);
        $connection = $this->firebird->getProductionConnection();
        $connection
            ->table('INVFISVSTEOPT')
            ->insert([
                'CODIGO'     => $codigoCeros,
                'CODIGOENT'  => (int) $codigoLimpio,
                'FECHAYHORA' => now(),
                'PROCESADO'  => 0
            ]);

        return response()->json([
            'ok'     => true,
            'codigo' => $codigoCeros
        ]);
    }
}