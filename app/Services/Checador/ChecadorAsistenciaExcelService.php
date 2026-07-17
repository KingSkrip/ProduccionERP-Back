<?php
// app/Services/Checador/ChecadorAsistenciaExcelService.php
namespace App\Services\Checador;

use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ChecadorAsistenciaExcelService
{
    private const EMPRESA_NOMBRE = 'COMERCIALIZADORA FIBRASAN S.A. DE C.V.';

    public function __construct(protected ChecadorAsistenciaService $asistenciaService) {}

    /**
     * Excel de UN empleado, con el mismo layout de "Tarjeta de Asistencia".
     */
    public function excelIndividual(UserFirebirdIdentity $identity, string $fechaEnSemana): Spreadsheet
    {
        $tarjeta = $this->asistenciaService->tarjetaSemana($identity, $fechaEnSemana);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->escribirHoja($spreadsheet, $tarjeta, $identity);

        return $spreadsheet;
    }

    /**
     * Excel de TODOS los empleados filtrados: un libro, una hoja por empleado.
     */
    public function excelEquipo(string $fechaEnSemana, ?string $empresa = null): Spreadsheet
    {
        // Reutilizamos la query interna del servicio de asistencia (todos, sin paginar).
        $identidades = app(\App\Models\UserFirebirdIdentity::class)::query()
            ->where('firebird_tb_tabla', 'like', 'TB%')
            ->when($empresa, fn ($q) => $q->where('firebird_empresa', $empresa))
            ->with(['firebirdUser', 'turnoActivo.turno.turnoDias'])
            ->orderBy('firebird_empresa')
            ->orderBy('firebird_user_clave')
            ->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($identidades as $identity) {
            $tarjeta = $this->asistenciaService->tarjetaSemana($identity, $fechaEnSemana);
            $this->escribirHoja($spreadsheet, $tarjeta, $identity);
        }

        // Si no hubo nadie que coincidiera, deja al menos una hoja vacía para no romper el writer.
        if ($spreadsheet->getSheetCount() === 0) {
            $spreadsheet->createSheet();
        }

        return $spreadsheet;
    }

    private function escribirHoja(Spreadsheet $spreadsheet, array $tarjeta, UserFirebirdIdentity $identity): void
    {
        $nombreHoja = $this->nombreHojaSeguro($tarjeta['nombre'] ?: "ID{$identity->id}");
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($nombreHoja);

        // Fuente base
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $col = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $lastCol = 'G';

        $row = 1;

        $sheet->setCellValue("A{$row}", 'Usuario: ' . (auth()->user()->name ?? 'RH'));
        $row++;

        $sheet->setCellValue("A{$row}", now()->locale('es')->isoFormat('ddd., D-MMM-YYYY @ h:mm:ss a'));
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $sheet->setCellValue("A{$row}", 'Tarjeta de Asistencia');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $sheet->setCellValue("A{$row}", self::EMPRESA_NOMBRE);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $desde = Carbon::parse($tarjeta['semana']['desde'])->locale('es')->isoFormat('ddd., DD-MMM-YYYY');
        $hasta = Carbon::parse($tarjeta['semana']['hasta'])->locale('es')->isoFormat('ddd., DD-MMM-YYYY');
        $sheet->setCellValue("A{$row}", "Del {$desde} al {$hasta}.");
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        $sheet->setCellValue("A{$row}", "{$identity->numero_credencial} {$tarjeta['nombre']}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue("A{$row}", 'PUESTO: ' . ($identity->puestoActivo?->puesto?->nombre ?? 'N/D'));
        $sheet->setCellValue("D{$row}", 'Tarjeta: ' . ($identity->numero_credencial ?? 'N/D'));
        $sheet->setCellValue("F{$row}", 'Turno: ' . ($tarjeta['turno']['nombre'] ?? 'Sin turno'));
        $row += 2;

        // Encabezado de la tabla de días
        $headerRow = $row;
        $encabezados = ['Fecha', 'Día', 'Horario esperado', 'Entrada', 'Salida', 'Horas', 'Permisos / Notas'];
        foreach ($encabezados as $i => $texto) {
            $celda = $col[$i] . $headerRow;
            $sheet->setCellValue($celda, $texto);
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E2E2');
        $row++;

        foreach ($tarjeta['dias'] as $dia) {
            $permisosTexto = collect($dia['permisos'])
                ->map(function ($p) {
                    $rango = $p['no_regresa']
                        ? ($p['hora_inicio'] ?? '—') . ' - no regresa'
                        : ($p['hora_inicio'] ?? '—') . ' - ' . ($p['hora_fin'] ?? '—');
                    return "{$p['tipo']}: {$rango}";
                })
                ->implode(' | ');

            $sinSalida = !empty($dia['hora_entrada_real']) && empty($dia['hora_salida_real']);

            $sheet->setCellValue("A{$row}", $dia['fecha']);
            $sheet->setCellValue("B{$row}", ucfirst($dia['dia_semana']));
            $sheet->setCellValue("C{$row}", $dia['horario_esperado']);
            $sheet->setCellValue("D{$row}", $dia['hora_entrada_real'] ?? ($dia['es_descanso'] ? '' : '—'));
            $sheet->setCellValue("E{$row}", $dia['hora_salida_real'] ?? ($sinSalida ? 'Ent. Sin Sal.' : ($dia['es_descanso'] ? '' : '—')));
            $sheet->setCellValue("F{$row}", $dia['horas_trabajadas']);
            $sheet->setCellValue("G{$row}", $permisosTexto ?: '—');

            if ($dia['es_descanso']) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->getColor()->setRGB('9E9E9E');
            }
            $row++;
        }

        $sheet->setCellValue("E{$row}", 'Total Horas Semana:');
        $sheet->getStyle("E{$row}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$row}", $tarjeta['total_horas_semana']);
        $sheet->getStyle("F{$row}")->getFont()->setBold(true);
        $row += 3;

        $sheet->setCellValue("A{$row}", '_____________________________');
        $sheet->setCellValue("D{$row}", '_____________________________');
        $sheet->setCellValue("F{$row}", '_____________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'TRABAJADOR: ' . $tarjeta['nombre']);
        $sheet->setCellValue("D{$row}", 'JEFE DE DEPARTAMENTO');
        $sheet->setCellValue("F{$row}", 'Vo. Bo. RH');

        // Anchos de columna
        foreach ($col as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}" . ($row))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function nombreHojaSeguro(string $texto): string
    {
        $limpio = preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $texto);
        return mb_substr(trim($limpio), 0, 31); // límite de Excel para nombres de hoja
    }

    public function guardarTemporal(Spreadsheet $spreadsheet, string $nombreArchivo): string
    {
        $path = storage_path('app/tmp/' . $nombreArchivo);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        (new Xlsx($spreadsheet))->save($path);
        return $path;
    }
}