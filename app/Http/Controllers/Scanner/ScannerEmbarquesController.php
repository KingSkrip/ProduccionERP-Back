<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Events\Scanner\ScanEmbarqueCreado;
use Illuminate\Http\Request;
use App\Services\FirebirdConnectionService;
use Illuminate\Support\Facades\Cache;

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
        $scannedBy = Cache::get('scanner_operador_activo');
    
        // 👇 temporal para debuggear
        \Illuminate\Support\Facades\Log::info('SCAN_DEBUG', [
            'scannedBy_from_cache' => $scannedBy,
            'cache_key' => 'scanner_operador_activo',
        ]);
    
        $connection = $this->firebird->getProductionConnection();
        $connection->table('INVFISVSTEOPT')->insert([
            'CODIGO'     => $codigoCeros,
            'CODIGOENT'  => (int) $codigoLimpio,
            'FECHAYHORA' => $fechaYHora,
            'PROCESADO'  => 0
        ]);
    
        broadcast(new ScanEmbarqueCreado(
            codigo:     $codigoCeros,
            codigoEnt:  (int) $codigoLimpio,
            fechaYHora: $fechaYHora,
            procesado:  0,
            scannedBy:  $scannedBy,
        ));
    
        return response()->json(['ok' => true, 'codigo' => $codigoCeros]);
    }

// ScannerEmbarquesController.php
public function registrarOperador(Request $request)
{
    $userId = $request->input('user_id');
    Cache::put('scanner_operador_activo', $userId, now()->addHours(8));
    return response()->json(['ok' => true, 'operador' => $userId]);
}

}
