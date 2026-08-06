<?php
// app/Services/Checador/ChecadorAsistenciaService.php
namespace App\Services\Checador;

use App\Models\ChecadorPermiso;
use App\Models\ChecadorRegistro;
use App\Models\UserFirebirdIdentity;
use App\Models\Turno;
use App\Models\TurnoDia;
use App\Services\FirebirdConnectionService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChecadorAsistenciaService
{

    public function __construct(
        protected FirebirdConnectionService $firebirdService,
    ) {}

    /**
     * Query base: SOLO identidades que son empleados reales (tabla TB de Firebird),
     * excluye clientes/proveedores/vendedores/usuarios de sistema que también
     * viven en users_firebird_identities.
     */
    private function queryEmpleados(
        ?string $empresa = null,
        ?int $areaId = null,
        ?int $departamentoId = null,
        ?int $turnoId = null,
        ?int $catalogoId = null,
        ?string $busqueda = null,
    ) {
        $query = UserFirebirdIdentity::query()
            ->where('firebird_tb_tabla', 'like', 'TB%')
            ->aptasParaChecador()      
            ->with(['firebirdUser', 'turnoActivo.turno.turnoDias'])
            ->orderBy('firebird_empresa')
            ->orderBy('firebird_user_clave');

        if ($empresa) {
            $query->where('firebird_empresa', $empresa);
        }

        if ($turnoId) {
            $query->whereHas('turnoActivo', fn($q) => $q->where('turno_id', $turnoId));
        }

        if ($catalogoId) {
            $query->whereHas('permisos', fn($q) => $q->where('catalogo_id', $catalogoId));
        }

        if ($areaId) {
            $query->whereHas('puestoActivo', fn($q) => $q->where('area_id', $areaId));
        }

        if ($departamentoId) {
            $query->whereHas('puestoActivo', fn($q) => $q->where('puesto_id', $departamentoId));
        }

        if ($busqueda) {
            $claves = $this->buscarClavesPorNombre($busqueda);
            $query->whereIn('firebird_user_clave', $claves ?: [-1]);
        }

        return $query;
    }

    private function buscarClavesPorNombre(string $busqueda): array
    {
        $connection = $this->firebirdService->getProductionConnection();

        $filas = $connection->select(
            "SELECT ID FROM USUARIOS WHERE NOMBRE LIKE ?",
            ["%{$busqueda}%"]
        );

        return array_map(fn($row) => (int) $row->ID, $filas);
    }


    /**
     * Tarjeta de UN empleado para la semana de $fechaEnSemana.
     */
    public function tarjetaSemana(UserFirebirdIdentity $identity, string $fechaEnSemana): array
    {
        $lunes = Carbon::parse($fechaEnSemana)->startOfWeek(Carbon::MONDAY);
        $domingo = $lunes->copy()->endOfWeek(Carbon::SUNDAY);

        if (!$identity->relationLoaded('turnoActivo')) {
            $identity->load('turnoActivo.turno.turnoDias');
        }
        $turno = $identity->turnoActivo->turno ?? null;

        $registros = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->whereBetween('fecha', [$lunes->toDateString(), $domingo->toDateString()])
            ->where('valido', true)
            ->orderBy('fecha_hora')
            ->get()
            ->groupBy(fn($r) => $r->fecha->format('Y-m-d'));

        $permisos = ChecadorPermiso::with('catalogo')
            ->where('user_firebird_identity_id', $identity->id)
            ->where('estado', 'aprobado')
            ->where(function ($q) use ($lunes, $domingo) {
                $q->whereBetween('fecha_inicio', [$lunes->toDateString(), $domingo->toDateString()])
                    ->orWhereBetween('fecha_fin', [$lunes->toDateString(), $domingo->toDateString()]);
            })
            ->get()
            ->groupBy(fn($p) => Carbon::parse($p->fecha_inicio)->format('Y-m-d'));

        $dias = [];
        $totalMinutos = 0;

        foreach (CarbonPeriod::create($lunes, $domingo) as $dia) {
            $key = $dia->format('Y-m-d');
            $regsDia = $registros->get($key, collect());
            $permisosDia = $permisos->get($key, collect());

            $horario = $this->horarioEsperado($turno, $dia);

            // Entrada del día = el PRIMER registro de tipo "entrada".
            $entrada = $regsDia->firstWhere('tipo', 'entrada');

            // Salida del día = el ÚLTIMO movimiento del día, SOLO si ese
            // movimiento representa que el empleado efectivamente "salió"
            // (salida normal, o un permiso que se quedó abierto/no_regresa
            // ese mismo día). Si el último evento fue "entrada" o
            // "Fin de permiso", el empleado sigue técnicamente adentro y
            // no hay salida real que mostrar todavía.
            //
            // Esto corrige el caso donde el filtro viejo
            // ($regsDia->where('tipo','salida')->last()) no detectaba un
            // "Inicio de permiso" sin cerrar como la salida del día, y
            // dejaba la columna Salida en "—" aunque el empleado sí se
            // había ido.
            $ultimoRegistroDia = $regsDia->last();

            $salida = ($ultimoRegistroDia && in_array($ultimoRegistroDia->tipo, ['salida', 'Inicio de permiso'], true))
                ? $ultimoRegistroDia
                : $regsDia->where('tipo', 'salida')->last();

            $minutosDia = ($entrada && $salida)
                ? $entrada->fecha_hora->diffInMinutes($salida->fecha_hora)
                : 0;

            $totalMinutos += $minutosDia;

            $dias[] = [
                'fecha' => $key,
                'dia_semana' => $dia->locale('es')->isoFormat('dddd'),
                'es_descanso' => $horario['es_descanso'],
                'horario_esperado' => $horario['es_descanso']
                    ? 'Descanso'
                    : ($horario['entrada'] && $horario['salida']
                        ? "{$horario['entrada']} - {$horario['salida']}"
                        : 'Sin turno asignado'),
                'hora_entrada_real' => $entrada?->fecha_hora->format('H:i'),
                'hora_salida_real' => $salida?->fecha_hora->format('H:i'),
                'metodo_entrada' => $entrada?->metodo,
                'metodo_salida' => $salida?->metodo,
                'horas_trabajadas' => round($minutosDia / 60, 2),
                // 👇 lo que faltaba: hora de entrada/salida de CADA permiso tomado ese día
                'permisos' => $permisosDia->map(fn($p) => [
                    'id' => $p->id,
                    'tipo' => $p->catalogo->nombre ?? 'Permiso',
                    'hora_inicio' => $p->hora_inicio ? Carbon::parse($p->hora_inicio)->format('H:i') : null,
                    'hora_fin' => $p->hora_fin ? Carbon::parse($p->hora_fin)->format('H:i') : null,
                    'no_regresa' => (bool) $p->no_regresa,
                    'motivo' => $p->motivo,
                ])->values(),
            ];
        }

        return [
            'identity_id' => $identity->id,
            'nombre' => trim((string) ($identity->firebirdUser->NOMBRE ?? "Empleado #{$identity->id}")),
            'empresa' => $identity->firebird_empresa,
            'turno' => $turno ? ['id' => $turno->id, 'nombre' => $turno->nombre] : null,
            'semana' => ['desde' => $lunes->toDateString(), 'hasta' => $domingo->toDateString()],
            'dias' => $dias,
            'total_horas_semana' => round($totalMinutos / 60, 2),
        ];
    }

    private function horarioEsperado(?Turno $turno, Carbon $dia): array
    {
        if (!$turno) {
            return ['es_descanso' => false, 'entrada' => null, 'salida' => null];
        }

        /** @var TurnoDia|null $turnoDia */
        $turnoDia = $turno->turnoDias->firstWhere('dia_semana', $dia->dayOfWeek);

        if ($turnoDia && $turnoDia->es_descanso) {
            return ['es_descanso' => true, 'entrada' => null, 'salida' => null];
        }

        $entrada = $turnoDia?->hora_entrada ?? $turno->hora_entrada;
        $salida = $turnoDia?->hora_salida ?? $turno->hora_salida;

        return [
            'es_descanso' => false,
            'entrada' => $entrada ? Carbon::parse($entrada)->format('H:i') : null,
            'salida' => $salida ? Carbon::parse($salida)->format('H:i') : null,
        ];
    }

    /**
     * Tarjetas de TODOS los empleados (paginado), con o sin turno asignado.
     */
    public function tarjetaEquipo(
        string $fechaEnSemana,
        ?string $empresa = null,
        int $page = 1,
        int $perPage = 20,
        ?int $areaId = null,
        ?int $departamentoId = null,
        ?int $turnoId = null,
        ?int $catalogoId = null,
        ?string $busqueda = null,
    ): array {
        $paginator = $this->queryEmpleados($empresa, $areaId, $departamentoId, $turnoId, $catalogoId, $busqueda)
            ->paginate($perPage, ['*'], 'page', $page);

        Log::info('TARJETA_EQUIPO_DEBUG', [
            'fecha' => $fechaEnSemana,
            'empresa' => $empresa,
            'area_id' => $areaId,
            'departamento_id' => $departamentoId,
            'turno_id' => $turnoId,
            'catalogo_id' => $catalogoId,
            'busqueda' => $busqueda,
            'total_paginator' => $paginator->total(),
            'items_esta_pagina' => $paginator->count(),
        ]);

        $tarjetas = $paginator->getCollection()
            ->map(function (UserFirebirdIdentity $identity) use ($fechaEnSemana) {
                try {
                    return $this->tarjetaSemana($identity, $fechaEnSemana);
                } catch (\Throwable $e) {
                    Log::error('TARJETA_SEMANA_FALLO', [
                        'identity_id' => $identity->id,
                        'error' => $e->getMessage(),
                        'linea' => $e->getLine(),
                    ]);
                    return null;
                }
            })
            ->filter()
            ->values();

        return [
            'data' => $tarjetas,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }

    public function csvSemana(UserFirebirdIdentity $identity, string $fechaEnSemana): string
    {
        $tarjeta = $this->tarjetaSemana($identity, $fechaEnSemana);
        return $this->tarjetaACsv($tarjeta);
    }

    /**
     * CSV de TODOS los empleados filtrados (uno detrás de otro, separados por encabezado).
     */
    public function csvEquipo(string $fechaEnSemana, ?string $empresa = null): string
    {
        $empleados = $this->queryEmpleados($empresa)->get();

        $handle = fopen('php://temp', 'r+');

        foreach ($empleados as $identity) {
            $tarjeta = $this->tarjetaSemana($identity, $fechaEnSemana);
            fputcsv($handle, ["Empleado", $tarjeta['nombre'], "Empresa", $tarjeta['empresa'], "Turno", $tarjeta['turno']['nombre'] ?? 'Sin turno']);
            fputcsv($handle, ['Fecha', 'Día', 'Horario esperado', 'Entrada real', 'Salida real', 'Horas trabajadas', 'Permisos (tipo hora_inicio-hora_fin)']);

            foreach ($tarjeta['dias'] as $d) {
                $permisosTexto = collect($d['permisos'])
                    ->map(fn($p) => "{$p['tipo']} ({$p['hora_inicio']}-{$p['hora_fin']})")
                    ->implode(' | ');

                fputcsv($handle, [
                    $d['fecha'],
                    $d['dia_semana'],
                    $d['horario_esperado'],
                    $d['hora_entrada_real'] ?? '-',
                    $d['hora_salida_real'] ?? '-',
                    $d['horas_trabajadas'],
                    $permisosTexto,
                ]);
            }
            fputcsv($handle, ['Total horas de la semana', $tarjeta['total_horas_semana']]);
            fputcsv($handle, []);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function tarjetaACsv(array $tarjeta): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Fecha', 'Día', 'Horario esperado', 'Entrada real', 'Salida real', 'Horas trabajadas', 'Permisos']);

        foreach ($tarjeta['dias'] as $d) {
            $permisosTexto = collect($d['permisos'])
                ->map(fn($p) => "{$p['tipo']} ({$p['hora_inicio']}-{$p['hora_fin']})")
                ->implode(' | ');

            fputcsv($handle, [
                $d['fecha'],
                $d['dia_semana'],
                $d['horario_esperado'],
                $d['hora_entrada_real'] ?? '-',
                $d['hora_salida_real'] ?? '-',
                $d['horas_trabajadas'],
                $permisosTexto,
            ]);
        }
        fputcsv($handle, []);
        fputcsv($handle, ['Total horas de la semana', $tarjeta['total_horas_semana']]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function identidadesFiltradas(
        ?string $empresa = null,
        ?int $areaId = null,
        ?int $departamentoId = null,
        ?int $turnoId = null,
        ?int $catalogoId = null,
        ?string $busqueda = null,
    ) {
        return $this->queryEmpleados($empresa, $areaId, $departamentoId, $turnoId, $catalogoId, $busqueda)->get();
    }
}