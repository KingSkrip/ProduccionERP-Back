<?php

namespace App\Http\Colaboradores;

use App\Models\create_departamentos_table;
use App\Http\Controllers\Controller;
use App\Http\Resources\ColaboradoresAreaResource;
use App\Models\Departamento;
use App\Models\DepSupervisor;
use App\Models\UserDepartamentoHistorial;
use App\Models\Users;
use App\Models\Vacacion;
use App\Models\VacacionHistorial;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SoliVacacionesController extends Controller
{
    /**
     * Mostrar todos los colaboradores
     */
    public function index()
    {
        $colaboradores = Users::with(['departamento', 'vacaciones', 'sueldos'])->get();
        return response()->json($colaboradores);
    }

    /**
     * Formulario para crear un colaborador
     */
    public function create()
    {
        $departamentos = Departamento::all();
        return response()->json(['departamentos' => $departamentos]);
    }

    /**
     * crear solicitudes de vacaciones
     */
    public function store(Request $request)
    {
        // Validación
        $request->validate([
            'fecha_inicio' => 'required|date|after_or_equal:today',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'comentarios' => 'nullable|string|max:500',
        ]);

        $usuario = Auth::user();

        // Obtener el departamento actual del usuario desde su historial
        $departamentoHistorial = $usuario->departamentosHistorial()
            ->whereNull('fecha_fin') // si usas null para indicar departamento activo
            ->orWhere('fecha_fin', '>=', now()->toDateString()) // o departamento que todavía está vigente
            ->orderBy('fecha_inicio', 'desc')
            ->first();

        if (!$departamentoHistorial) {
            return response()->json([
                'error' => 'No tienes un departamento activo asignado.'
            ], 400);
        }



        $departamentoId = $departamentoHistorial->departamento_id;

        // Buscar supervisor en ese departamento
        $supervisor = DepSupervisor::where('departamento_id', $departamentoId)
            ->where('user_id', '!=', $usuario->id)
            ->first();

        if (!$supervisor) {
            return response()->json([
                'error' => 'No se encontró un supervisor asignado para tu departamento.'
            ], 400);
        }

        // 'fecha_inicio' => $request->fecha_inicio,
        // 'fecha_fin' => $request->fecha_fin,

        $vacacion = Vacacion::where('user_id', $usuario->id)
            ->where('anio', date('Y')) // ajusta si usas otro año
            ->first();

        // Crear la WorkOrder
        $workOrder = WorkOrder::create([
            'solicitante_id' => $usuario->id,
            'aprobador_id' => $supervisor->user_id,
            'status_id' => 5,
            'titulo' => 'Vacaciones',
            'descripcion' => "Solicitud de vacaciones del {$request->fecha_inicio} al {$request->fecha_fin}",
            'fecha_solicitud' => now()->toDateString(),
            'comentarios_solicitante' => $request->comentarios,
        ]);

        //   Log::info("departamento", $departamentoId);
        // return;

        // Create Vacaciones Historial
        $VHisotiral = VacacionHistorial::create([
            'vacacion_id' => $vacacion->id,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'dias' => $request->dias,
            'comentarios' => $request->comentarios_solicitante,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'vacacion' => $vacacion,
                'solicitud' => $workOrder,
                'historial' => $VHisotiral
            ]
        ]);
    }

    /**
     * Mostrar detalles de un colaborador
     */
    public function show($id)
    {
        $colaborador = Users::with(['departamento', 'vacaciones', 'sueldos'])->findOrFail($id);
        return response()->json($colaborador);
    }

    /**
     * Mostrar datos de la solicitud para edición
     */
    public function edit($id)
    {
        $usuarioLogueado = Auth::user();

        // Verificar si el usuario logueado es jefe de algún departamento
        $esJefe = DepSupervisor::where('user_id', $usuarioLogueado->id)->exists();

        if ($esJefe) {
            // Obtener los departamentos donde es jefe
            $departamentosJefe = DepSupervisor::where('user_id', $usuarioLogueado->id)
                ->pluck('departamento_id');

            // Obtener usuarios que tienen historial en esos departamentos
            $usuariosIds = UserDepartamentoHistorial::whereIn('departamento_id', $departamentosJefe)
                ->whereNull('fecha_fin') // Solo usuarios activos en el departamento
                ->pluck('user_id')
                ->unique();

            // Obtener los usuarios completos con todas sus relaciones
            $usuarios = Users::whereIn('id', $usuariosIds)
                ->with([
                    'status',
                    'direccion',
                    'departamento',
                    'roles.subrol',
                    'modelHasStatuses.status',
                    'empleos',
                    'fiscal',
                    'seguridadSocial',
                    'nomina',
                    'sueldos.historial',
                    'departamentosHistorial.departamento',
                    'vacaciones.historial',
                    'asistencias.turno',
                    'notificaciones',
                    'bonos',
                    'tiemposExtra',
                    'passwordResets',
                    'workordersSolicitadas.status',
                    'workordersAprobadas.status',
                    'workordersAprobadas.solicitante',

                ])
                ->get();
        } else {
            // Obtener el departamento actual del usuario logueado
            $departamentoActual = UserDepartamentoHistorial::where('user_id', $usuarioLogueado->id)
                ->whereNull('fecha_fin')
                ->first();

            if (!$departamentoActual) {
                return response()->json([
                    'message' => 'No tienes un departamento asignado actualmente',
                    'data' => [],
                    'total' => 0
                ], 200);
            }

            // Obtener usuarios del mismo departamento
            $usuariosIds = UserDepartamentoHistorial::where('departamento_id', $departamentoActual->departamento_id)
                ->whereNull('fecha_fin')
                ->pluck('user_id')
                ->unique();

            // Obtener los usuarios completos con todas sus relaciones
            $usuarios = Users::whereIn('id', $usuariosIds)
                ->with([
                    'status',
                    'direccion',
                    'departamento',
                    'roles.subrol',
                    'modelHasStatuses.status',
                    'empleos',
                    'fiscal',
                    'seguridadSocial',
                    'nomina',
                    'sueldos.historial',
                    'departamentosHistorial.departamento',
                    'vacaciones.historial',
                    'asistencias.turno',
                    'notificaciones',
                    'bonos',
                    'tiemposExtra',
                    'passwordResets',
                    'workordersSolicitadas.status',
                    'workordersAprobadas.status',
                    'workordersAprobadas.solicitante',

                ])
                ->get();
        }

        return new ColaboradoresAreaResource($usuarios);
    }

    /**
     * Obtener un usuario específico por ID (solo si está relacionado)
     */



    /**
     * Actualizar solicitudes de vacaciones
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'fecha_inicio' => 'sometimes|date|after_or_equal:today',
            'fecha_fin' => 'sometimes|date|after_or_equal:fecha_inicio',
            'dias' => 'sometimes|integer|min:1',
            'comentarios' => 'nullable|string|max:500',
            'status_id' => 'nullable|integer|in:3,4,5',
        ]);

        $usuario = Auth::user();

        DB::beginTransaction();

        try {

            // Historial
            $historial = VacacionHistorial::with('vacacion')
                ->where('id', $id)
                ->firstOrFail();

            // Validar propietario
            if ($historial->vacacion->user_id !== $usuario->id && $request->status_id == 5) {
                return response()->json([
                    'error' => 'No tienes permisos para editar esta solicitud.'
                ], 403);
            }

            // WorkOrder asociada
            $workOrder = WorkOrder::where('solicitante_id', $historial->vacacion->user_id)
                ->where('titulo', 'Vacaciones')
                ->where('status_id', 5)
                ->latest()
                ->first();

            if (!$workOrder) {
                return response()->json([
                    'error' => 'No se encontró la orden de trabajo.'
                ], 404);
            }

            // ===============================
            // 🔴 RECHAZADO
            // ===============================
            if ($request->status_id == 4) {

                $historial->delete();

                $workOrder->update([
                    'status_id' => 4
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Solicitud rechazada'
                ]);
            }

            // ===============================
            // 🟢 APROBADO
            // ===============================
            if ($request->status_id == 3) {

                $vacacion = $historial->vacacion;

                $vacacion->update([
                    'dias_disfrutados' => ($vacacion->dias_disfrutados ?? 0) + $historial->dias
                ]);

                $workOrder->update([
                    'status_id' => 3,
                    'fecha_aprobacion' => Carbon::now(),
                    'fecha_cierre' => Carbon::now(),
                    'comentarios_aprobador' => "",
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Vacaciones aprobadas'
                ]);
            }

            // ===============================
            // ✏️ EDICIÓN NORMAL (PENDIENTE)
            // ===============================
            if ($workOrder->status_id !== 5) {
                return response()->json([
                    'error' => 'La solicitud ya no puede ser editada.'
                ], 400);
            }

            $historial->update([
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'dias' => $request->dias,
                'comentarios' => $request->comentarios,
            ]);

            $workOrder->update([
                'fecha_aprobacion' => "",
                'fecha_cierre' => "",
                'comentarios_aprobador' => "",
                'descripcion' => "Solicitud de vacaciones del {$request->fecha_inicio} al {$request->fecha_fin}",
                'comentarios_solicitante' => $request->comentarios,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Solicitud actualizada correctamente'
            ]);
        } catch (Exception $e) {

            DB::rollBack();

            return response()->json([
                'error' => 'Error al procesar la solicitud',
                'detalle' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un colaborador
     */
    public function destroy($id)
    {
        $colaborador = Users::findOrFail($id);
        $colaborador->delete();

        return response()->json(['message' => 'Colaborador eliminado']);
    }
}
