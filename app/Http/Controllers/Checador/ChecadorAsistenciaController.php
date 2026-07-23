<?php
// app/Http/Controllers/Checador/ChecadorAsistenciaController.php
namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Models\UserFirebirdIdentity;
use App\Services\Checador\ChecadorAsistenciaExcelService;
use App\Services\Checador\ChecadorAsistenciaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ChecadorAsistenciaController extends Controller
{
    public function __construct(
        protected ChecadorAsistenciaService $service,
        protected ChecadorAsistenciaExcelService $excelService,
    ) {}

    public function resumenSemana(Request $request, int $identityId)
    {
        $identity = UserFirebirdIdentity::findOrFail($identityId);
        $fecha = $request->query('fecha', now()->toDateString());

        return response()->json($this->service->tarjetaSemana($identity, $fecha));
    }

    public function resumenEquipo(Request $request)
    {
        $fecha = $request->query('fecha', now()->toDateString());
        $empresa = $request->query('empresa');
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $areaId = $request->query('area_id');
        $departamentoId = $request->query('departamento_id');
        $turnoId = $request->query('turno_id');
        $catalogoId = $request->query('catalogo_id');
        $busqueda = $request->query('busqueda');

        return response()->json($this->service->tarjetaEquipo(
            $fecha,
            $empresa,
            $page,
            $perPage,
            $areaId ? (int) $areaId : null,
            $departamentoId ? (int) $departamentoId : null,
            $turnoId ? (int) $turnoId : null,
            $catalogoId ? (int) $catalogoId : null,
            $busqueda,
        ));
    }
    public function excelSemana(Request $request, int $identityId)
    {
        $identity = UserFirebirdIdentity::findOrFail($identityId);
        $fecha = $request->query('fecha', now()->toDateString());

        $spreadsheet = $this->excelService->excelIndividual($identity, $fecha);
        $archivo = "asistencia_{$identity->id}_{$fecha}.xlsx";
        $path = $this->excelService->guardarTemporal($spreadsheet, $archivo);

        return response()->download($path, $archivo)->deleteFileAfterSend(true);
    }

    // public function excelEquipo(Request $request)
    // {
    //     $fecha = $request->query('fecha', now()->toDateString());
    //     $empresa = $request->query('empresa');

    //     $spreadsheet = $this->excelService->excelEquipo($fecha, $empresa);
    //     $archivo = "asistencia_equipo_{$fecha}" . ($empresa ? "_emp{$empresa}" : '') . '.xlsx';
    //     $path = $this->excelService->guardarTemporal($spreadsheet, $archivo);

    //     return response()->download($path, $archivo)->deleteFileAfterSend(true);
    // }

    public function excelEquipo(Request $request)
    {
        $fecha = $request->query('fecha', now()->toDateString());
        $empresa = $request->query('empresa');
        $areaId = $request->query('area_id');
        $departamentoId = $request->query('departamento_id');
        $turnoId = $request->query('turno_id');
        $busqueda = $request->query('busqueda');

        $spreadsheet = $this->excelService->excelEquipo(
            $fecha,
            $empresa,
            $areaId ? (int) $areaId : null,
            $departamentoId ? (int) $departamentoId : null,
            $turnoId ? (int) $turnoId : null,
            null, // catalogoId — el modal no lo usa, se manda null
            $busqueda,
        );

        $archivo = "asistencia_equipo_{$fecha}" . ($empresa ? "_emp{$empresa}" : '') . '.xlsx';
        $path = $this->excelService->guardarTemporal($spreadsheet, $archivo);

        return response()->download($path, $archivo)->deleteFileAfterSend(true);
    }


    public function listaEmpleados(Request $request)
    {
        $empresa = $request->query('empresa');
        $areaId = $request->query('area_id');
        $departamentoId = $request->query('departamento_id');
        $turnoId = $request->query('turno_id');

        $identidades = $this->service->identidadesFiltradas(
            $empresa,
            $areaId ? (int) $areaId : null,
            $departamentoId ? (int) $departamentoId : null,
            $turnoId ? (int) $turnoId : null,
        );

        $lista = $identidades->map(fn($identity) => [
            'id' => $identity->id,
            'nombre' => trim((string) ($identity->firebirdUser->NOMBRE ?? "Empleado #{$identity->id}")),
        ])->sortBy('nombre')->values();

        return response()->json($lista);
    }
}