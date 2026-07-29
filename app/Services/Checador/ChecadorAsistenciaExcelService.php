<?php
// app/Services/Checador/ChecadorAsistenciaExcelService.php
namespace App\Services\Checador;

use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageBreak;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ChecadorAsistenciaExcelService
{
    private const EMPRESAS = [
        '1' => 'GORDON LERMA GO',
        '2' => 'FIBRA 26',
        '3' => 'FIBRA BALLESTA',
        '4' => 'COMERCIALIZADORA FIBRASAN S.A. DE C.V.',
        '5' => 'BH CONTINENTAL',
    ];

    public function __construct(protected ChecadorAsistenciaService $asistenciaService) {}

    private function nombreEmpresa(UserFirebirdIdentity $identity): string
    {
        $codigoOriginal = (string) $identity->firebird_empresa;
        $codigo = (string) (int) $identity->firebird_empresa;

        return self::EMPRESAS[$codigo] ?? "EMPRESA {$codigoOriginal}";
    }

    /**
     * Excel de UN empleado, con el layout de "Tarjeta de Asistencia".
     */
    public function excelIndividual(UserFirebirdIdentity $identity, string $fechaEnSemana): Spreadsheet
    {
        $tarjeta = $this->asistenciaService->tarjetaSemana($identity, $fechaEnSemana);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->nombreHojaSeguro($tarjeta['nombre'] ?: "ID{$identity->id}"));
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $row = 1;
        $this->escribirBloqueEmpleado($sheet, $row, $tarjeta, $identity);
        $this->aplicarAnchoColumnas($sheet);

        return $spreadsheet;
    }

    /**
     * Excel de TODOS los empleados filtrados: UN SOLO libro, UNA SOLA hoja,
     * con el bloque de cada empleado uno debajo del otro.
     */
    public function excelEquipo(
        string $fechaEnSemana,
        ?string $empresa = null,
        ?int $areaId = null,
        ?int $departamentoId = null,
        ?int $turnoId = null,
        ?int $catalogoId = null,
        ?string $busqueda = null,
    ): Spreadsheet {
        $identidades = $this->asistenciaService->identidadesFiltradas(
            $empresa,
            $areaId,
            $departamentoId,
            $turnoId,
            $catalogoId,
            $busqueda
        );

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Asistencia Equipo');
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $row = 1;

        foreach ($identidades as $identity) {
            try {
                $tarjeta = $this->asistenciaService->tarjetaSemana($identity, $fechaEnSemana);
            } catch (\Throwable $e) {
                // Si un empleado falla, lo saltamos pero seguimos con el resto.
                continue;
            }

            if ($row > 1) {
                $sheet->setBreak("A{$row}", Worksheet::BREAK_ROW);
            }
            $this->escribirBloqueEmpleado($sheet, $row, $tarjeta, $identity);

            // Espacio en blanco entre un empleado y el siguiente.
            $row += 2;
        }

        // Si nadie coincidió, deja al menos un mensaje para no romper el writer.
        if ($row === 1) {
            $sheet->setCellValue('A1', 'No se encontraron empleados con los filtros seleccionados.');
        }

        $this->aplicarAnchoColumnas($sheet);

        return $spreadsheet;
    }

    /**
     * Escribe el bloque completo de UN empleado a partir de $row,
     * y deja $row apuntando a la siguiente fila libre al terminar.
     */
    private function escribirBloqueEmpleado($sheet, int &$row, array $tarjeta, UserFirebirdIdentity $identity): void
    {
        $col = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $lastCol = 'G';

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

        $sheet->setCellValue("A{$row}", $this->nombreEmpresa($identity));
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

        $sheet->setCellValue("A{$row}", 'AREA: ' . ($identity->puestoActivo?->puesto?->nombre ?? 'N/D'));
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
        $row++;

        // Bordes de la tabla de este bloque
        $sheet->getStyle("A{$headerRow}:{$lastCol}" . ($row - 4))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function aplicarAnchoColumnas($sheet): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
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