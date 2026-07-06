<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorAccessQrCodeResource;
use App\Http\Resources\Checador\ChecadorRegistroResource;
use App\Services\Checador\ChecadorQrService;
use Illuminate\Http\Request;

class ChecadorQrController extends Controller
{
    public function __construct(protected ChecadorQrService $qrService) {}

    public function generar(int $identityId)
    {
        $qr = $this->qrService->generar($identityId);

        return new ChecadorAccessQrCodeResource($qr);
    }

    public function mostrar(int $identityId)
    {
        $qr = $this->qrService->obtenerActivo($identityId);

        if (!$qr) {
            return response()->json(['message' => 'Esta identidad no tiene un QR activo'], 404);
        }

        return new ChecadorAccessQrCodeResource($qr);
    }

    public function revocar(int $identityId)
    {
        $qr = $this->qrService->revocar($identityId);

        if (!$qr) {
            return response()->json(['message' => 'No hay QR activo para revocar'], 404);
        }

        return response()->json(['message' => 'QR revocado correctamente']);
    }

    public function registrar(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        try {
            $resultado = $this->qrService->registrarChecada($request->token, [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return (new ChecadorRegistroResource($resultado))
                ->response()
                ->setStatusCode(201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function historial(Request $request, int $identityId)
    {
        $registros = $this->qrService->historial(
            $identityId,
            $request->query('desde'),
            $request->query('hasta')
        );

        return response()->json($registros);
    }
}