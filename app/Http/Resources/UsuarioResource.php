<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    protected array $ctx;

    public function __construct($resource, array $contexto = [])
    {
        parent::__construct($resource);
        $this->ctx = $contexto;
    }

    public function toArray($request)
    {
        // 🔥 AHORA LOS DATOS VIENEN DIRECTAMENTE COMO OBJETOS, NO COMO COLECCIONES INDEXADAS
        $depto = $this->ctx['departamentos'][$this->DEPTO] ?? null;

        // Ya no buscamos por CLAVE, vienen directamente del controller
        $sl    = $this->ctx['sl'] ?? null;
        $vc    = $this->ctx['vacaciones'] ?? null;
        $hvc   = $this->ctx['historialvacaciones'] ?? null;
        $mf    = $this->ctx['faltas'] ?? null;
        $ac    = $this->ctx['acumuladosperiodos'] ?? null;
        $tb    = $this->ctx['TB'] ?? null;
        $turnoActivo = $this->ctx['turnoActivo'] ?? null;


        $roles = collect($this->ctx['roles'] ?? []);

        return [
            // 🔹 IDENTIDAD FIREBIRD (MISMO CONTRATO)
            'firebird_user_clave'       => $this->CLAVE ? trim((string) $this->CLAVE) : null,
            'firebird_user_id'       => $this->ID ? trim((string) $this->ID) : null,
            'name'     => $this->NOMBRE ? trim((string) $this->NOMBRE) : null,
            'email'    => $this->CORREO ? trim((string) $this->CORREO) : null,
            'usuario'  => $this->USUARIO ? trim((string) $this->USUARIO) : null,
            'photo'    => $this->PHOTO ? trim((string) $this->PHOTO) : null,

            // 🔹 DATOS ADICIONALES DE TB (SRVNOI)
            'RFC'          => $tb ? ($tb->R_F_C_ ? trim((string) $tb->R_F_C_) : null) : null,
            'IMSS'         => $tb ? ($tb->IMSS ? trim((string) $tb->IMSS) : null) : null,
            'FECHA_ALTA'   => $tb ? ($tb->FECH_ALTA ?? null) : null,
            'STATUS'       => $tb ? ($tb->STATUS ? trim((string) $tb->STATUS) : null) : null,
            'TELEFONO'     => $tb ? ($tb->TELEFONO ? trim((string) $tb->TELEFONO) : null) : null,
            'SEXO'         => $tb ? ($tb->SEXO ? trim((string) $tb->SEXO) : null) : null,
            'SAL_DIARIO'   => $tb ? ($tb->SAL_DIARIO ? trim((string) $tb->SAL_DIARIO) : null) : null,
            'SUELDO_HORA'  => $tb ? ($tb->SUELDOXHORA ? trim((string) $tb->SUELDOXHORA) : null) : null,

            // 🔹 PERMISOS (MISMAS KEYS)
            'permissions'     => $roles->pluck('role_id')->unique()->values(),
            'sub_permissions' => $roles->pluck('subrol_id')->unique()->values(),

            // 🔹 DEPARTAMENTO
            'departamento' => $depto ? [
                'id'     => $depto->CLAVE ? trim((string) $depto->CLAVE) : null,
                'nombre' => $depto->NOMBRE ? trim((string) $depto->NOMBRE) : null,
            ] : null,


            // 🔹 SALARIO
            'SALARIO' => $sl ? [
                // 'CLAVE_TRAB' => $sl->CLAVE_TRAB ? trim((string) $sl->CLAVE_TRAB) : null,
                'FECH_MOV'   => $sl->FECH_MOV ?? null,
                'TIPO_MOV'   => $sl->TIPO_MOV ? trim((string) $sl->TIPO_MOV) : null,
                'PARTE_FIJA' => $sl->PARTE_FIJA ?? null,
                'PARTE_VAR'  => $sl->PARTE_VAR ?? null,
                'SAL_DIARIO' => $sl->SAL_DIARIO ?? null,
                'SDI_IMSS'   => $sl->SDI_IMSS ?? null,
                'SDI_INFONA' => $sl->SDI_INFONA ?? null,
            ] : null,


            // 🔹 VACACIONES
            'VACACIONES' => $vc ? [
                // 'CLAVE_TRAB' => $vc->CLAVE_TRAB ? trim((string) $vc->CLAVE_TRAB) : null,
                'ANIVER'     => $vc->ANIVER ?? null,
                'TIPO_MOV'   => $vc->TIPO_MOV ? trim((string) $vc->TIPO_MOV) : null,
                'FECH_PAGO'  => $vc->FECH_PAGO ?? null,
                'D_DISFRUTE' => $vc->D_DISFRUTE ?? null,
                'D_PRIMAVAC' => $vc->D_PRIMAVAC ?? null,
                'CVEPER'     => $vc->CVEPER ? trim((string) $vc->CVEPER) : null,
                'CVEVAC'     => $vc->CVEVAC ? trim((string) $vc->CVEVAC) : null,
                'EFECTIVO'   => $vc->EFECTIVO ?? null,
            ] : null,

            // 🔹 HISTORIAL VACACIONES
            'HISTORIAL_VACACIONES' => $hvc ? [
                'CVETRAB'   => $hvc->CVETRAB ? trim((string) $hvc->CVETRAB) : null,
                'TIPO'      => $hvc->TIPO ? trim((string) $hvc->TIPO) : null,
                'FECHA_MAX' => $hvc->FECHA_MAX ?? null,
                'FECHA'     => $hvc->FECHA ?? null,
                'FECHA_NOM' => $hvc->FECHA_NOM ?? null,
                'DIAS'      => $hvc->DIAS ?? null,
                'PAGADO'    => $hvc->PAGADO ?? null,
            ] : null,

            // 🔹 FALTAS
            'FALTAS' => $mf ? [
                // 'CLAVE_TRAB'   => $mf->CLAVE_TRAB ? trim((string) $mf->CLAVE_TRAB) : null,
                'FECH_INI'     => $mf->FECH_INI ?? null,
                'CLV_FALTA'    => $mf->CLV_FALTA ? trim((string) $mf->CLV_FALTA) : null,
                'REFERENCIA'   => $mf->REFERENCIA ? trim((string) $mf->REFERENCIA) : null,
                'DIAS_FALT'    => $mf->DIAS_FALT ?? null,
                'DIAS_PAGAD'   => $mf->DIAS_PAGAD ?? null,
                'PORCENTAJE'   => $mf->PORCENTAJE ?? null,
                'CLAVE_TIPOINC' => $mf->CLAVE_TIPOINC ? trim((string) $mf->CLAVE_TIPOINC) : null,
                'RE_TIPOINC'   => $mf->RE_TIPOINC ? trim((string) $mf->RE_TIPOINC) : null,
                'OBSERVACIONES' => $mf->OBSERVACIONES ? trim((string) $mf->OBSERVACIONES) : null,
            ] : null,

            // 🔹 ACUMULADOS
            'ACUMULADOS_PERIODOS' => $ac && $ac->count() ?
                $ac->map(function ($row) {
                    return [
                        'PER_O_PED'         => $row->PER_O_PED ?? null,
                        'NUM_PERDED'        => $row->NUM_PERDED ?? null,
                        'MEN_PESOS'         => $row->MEN_PESOS ?? null,
                        'ANUALPESOS'        => $row->ANUALPESOS ?? null,
                        'BIM_PESOS'         => $row->BIM_PESOS ?? null,
                        'ACUM_SDI_VAR_ANT'  => $row->ACUM_SDI_VAR_ANT ?? null,
                        'ACUM_SDI_VAR_BANT' => $row->ACUM_SDI_VAR_BANT ?? null,
                    ];
                })->values()
                : [],



            // 🔹 TB (SRVNOI)
            'TB' => $tb ? [
                'STATUS'     => $tb->STATUS ? trim((string) $tb->STATUS) : null,
                'RFC'        => $tb->R_F_C_ ? trim((string) $tb->R_F_C_) : null,
                'IMSS'       => $tb->IMSS ? trim((string) $tb->IMSS) : null,
                'FECH_ALTA'  => $tb->FECH_ALTA ?? null,
                'SAL_DIARIO' => $tb->SAL_DIARIO ? trim((string) $tb->SAL_DIARIO) : null,
                'SUELDOXHORA' => $tb->SUELDOXHORA ? trim((string) $tb->SUELDOXHORA) : null,
                'TELEFONO'   => $tb->TELEFONO ? trim((string) $tb->TELEFONO) : null,
                'SEXO'       => $tb->SEXO ? trim((string) $tb->SEXO) : null,
            ] : null,

            // 🔹 TURNO ACTIVO (FORMATO BD COMPLETO)
            'TURNO_ASIGNADO' => $turnoActivo ? [

                // ===== user_turnos =====
                'FECHA_INICIO' => $turnoActivo->fecha_inicio,
                'FECHA_FIN' => $turnoActivo->fecha_fin,
                'SEMANA_ANIO' => $turnoActivo->semana_anio_calculada,
                'DIAS_DESCANSO_PERSONALIZADOS' => $turnoActivo->dias_descanso_personalizados,

                'VIGENTE'     => $turnoActivo->esVigente(),
                'TRABAJA_HOY' => $turnoActivo->trabajaHoy(),

                // ===== turno_dias (HOY) =====
                'HORARIOS_HOY' => $turnoActivo->getHorariosHoy() ? [
                    'HORA_ENTRADA'        => $turnoActivo->getHorariosHoy()['hora_entrada'],
                    'HORA_SALIDA'         => $turnoActivo->getHorariosHoy()['hora_salida'],
                    'HORA_INICIO_COMIDA'  => $turnoActivo->getHorariosHoy()['hora_inicio_comida'],
                    'HORA_FIN_COMIDA'     => $turnoActivo->getHorariosHoy()['hora_fin_comida'],
                    'ENTRA_DIA_ANTERIOR'  => $turnoActivo->getHorariosHoy()['entra_dia_anterior'],
                    'SALE_DIA_SIGUIENTE'  => $turnoActivo->getHorariosHoy()['sale_dia_siguiente'],
                ] : null,

                // ===== TODOS LOS DÍAS DEL TURNO =====
                'DIAS_TURNO' => $turnoActivo->turno->turnoDias
                    ->sortBy('dia_semana')
                    ->map(function ($dia) {
                        return [
                            'DIA_SEMANA'          => $dia->dia_semana,        // 0-6
                            'NOMBRE_DIA'          => $dia->nombre_dia,        // Lunes, Martes...
                            'ES_LABORABLE'        => $dia->es_laborable,
                            'ES_DESCANSO'         => $dia->es_descanso,
                            'HORA_ENTRADA'        => $dia->hora_entrada,
                            'HORA_SALIDA'         => $dia->hora_salida,
                            'HORA_INICIO_COMIDA'  => $dia->hora_inicio_comida,
                            'HORA_FIN_COMIDA'     => $dia->hora_fin_comida,
                            'ENTRA_DIA_ANTERIOR'  => $dia->entra_dia_anterior,
                            'SALE_DIA_SIGUIENTE'  => $dia->sale_dia_siguiente,
                        ];
                    })
                    ->values(),

                // ===== status =====
                'STATUS' => [
                    'NOMBRE' => $turnoActivo->status->nombre ?? null,
                ],

                // ===== turnos =====
                'DETALLES_TURNO' => [
                    'CLAVE'               => $turnoActivo->turno->clave,
                    'NOMBRE'              => $turnoActivo->turno->nombre,
                    'HORA_ENTRADA'        => $turnoActivo->turno->hora_entrada,
                    'HORA_SALIDA'         => $turnoActivo->turno->hora_salida,
                    'HORA_INICIO_COMIDA'  => $turnoActivo->turno->hora_inicio_comida,
                    'HORA_FIN_COMIDA'     => $turnoActivo->turno->hora_fin_comida,
                    'ENTRA_DIA_ANTERIOR'  => $turnoActivo->turno->entra_dia_anterior,
                    'SALE_DIA_SIGUIENTE'  => $turnoActivo->turno->sale_dia_siguiente,
                ],

            ] : null,





            // 'departamento' => $depto ? [
            //     'id'     => $depto->CLAVE,
            //     'nombre' => $depto->NOMBRE,
            // ] : null,

            // Relación: Status
            // 'status' => $this->status ? [
            //     'id' => $this->status->id,
            //     'nombre' => $this->status->nombre,
            //     'descripcion' => $this->status->descripcion,
            // ] : null,

            // Relación: Dirección
            // 'direccion' => $this->direccion ? [
            //     'id'                 => $this->direccion->id,
            //     'calle'              => $this->direccion->calle,
            //     'no_ext'             => $this->direccion->no_ext,
            //     'no_int'             => $this->direccion->no_int,
            //     'colonia'            => $this->direccion->colonia,
            //     'cp'                 => $this->direccion->cp,
            //     'municipio'          => $this->direccion->municipio,
            //     'estado'             => $this->direccion->estado,
            //     'entidad_federativa' => $this->direccion->entidad_federativa,
            //     'pais'               => $this->direccion->pais,
            // ] : null,

            // 'departamento' => $this->departamento ? [
            //     'id'     => $this->departamento->id,
            //     'nombre' => $this->departamento->nombre,
            // ] : null,

            // 'roles' => $this->roles->map(function ($role) {
            //     return [
            //         'id'              => $role->id,
            //         'ROLE_CLAVE'      => $role->ROLE_CLAVE,
            //         'MODEL_CLAVE'     => $role->MODEL_CLAVE,
            //         'SUBROL_ID'       => $role->SUBROL_ID,
            //         'MODEL_TYPE'      => $role->MODEL_TYPE,
            //         'subrol'          => $role->subrol ? [
            //             'id'         => $role->subrol->id,
            //             'nombre'     => $role->subrol->nombre,
            //             'GUARD_NAME' => $role->subrol->GUARD_NAME,
            //         ] : null,
            //     ];
            // }),

            // 'MODEL_HAS_STATUSES' => $this->modelHasStatuses->map(function ($mhs) {
            //     return [
            //         'id'             => $mhs->id,
            //         'status_id'      => $mhs->status_id,
            //         'user_id'        => $mhs->user_id,
            //         'status_nombre'  => $mhs->status ? $mhs->status->nombre : null,
            //         'status_descripcion' => $mhs->status ? $mhs->status->descripcion : null,
            //         'created_at'     => $mhs->created_at ? $mhs->created_at->format('d/m/Y H:i:s') : null,
            //     ];
            // }),

            // Relación: Empleos
            // 'empleos' => $this->empleos->map(function ($empleo) {
            //     return [
            //         'id'            => $empleo->id,
            //         'puesto'        => $empleo->puesto,
            //         'fecha_inicio'  => $empleo->fecha_inicio ? Carbon::parse($empleo->fecha_inicio)->format('d/m/Y') : null,
            //         'fecha_fin'     => $empleo->fecha_fin ? Carbon::parse($empleo->fecha_fin)->format('d/m/Y') : null,
            //         'comentarios'   => $empleo->comentarios,
            //     ];
            // }),

            // Relación: Fiscal
            // 'fiscal' => $this->fiscal->first() ? [
            //     'id'             => $this->fiscal->first()->id,
            //     'rfc'            => $this->fiscal->first()->rfc,
            //     'regimen_fiscal' => $this->fiscal->first()->regimen_fiscal,
            // ] : null,


            // Relación: Seguridad Social
            // 'seguridad_social' => $this->seguridadSocial->first() ? [
            //     'id'          => $this->seguridadSocial->first()->id,
            //     'numero_imss' => $this->seguridadSocial->first()->numero_imss,
            //     'fecha_alta'  => $this->seguridadSocial->first()->fecha_alta ? Carbon::parse($this->seguridadSocial->first()->fecha_alta)->format('d/m/Y') : null,
            //     'tipo_seguro' => $this->seguridadSocial->first()->tipo_seguro,
            // ] : null,

            // Relación: Nómina
            // 'nomina' => $this->nomina->first() ? [
            //     'id'                  => $this->nomina->first()->id,
            //     'numero_tarjeta'      => $this->nomina->first()->numero_tarjeta,
            //     'banco'               => $this->nomina->first()->banco,
            //     'clabe_interbancaria' => $this->nomina->first()->clabe_interbancaria,
            //     'salario_base'        => $this->nomina->first()->salario_base,
            //     'frecuencia_pago'     => $this->nomina->first()->frecuencia_pago,
            // ] : null,

            // 'sueldo' => $this->sueldo ? [
            //     'id'             => $this->sueldo->id,
            //     'sueldo_diario'  => $this->sueldo->sueldo_diario,
            //     'sueldo_semanal'  => $this->sueldo->sueldo_semanal,
            //     'sueldo_mensual' => $this->sueldo->sueldo_mensual,
            //     'sueldo_anual'   => $this->sueldo->sueldo_anual,
            // ] : null,


            // 'sueldos_historial' => $this->sueldo && $this->sueldo->historial ?
            //     $this->sueldo->historial->map(function ($historial) {
            //         return [
            //             'id'             => $historial->id,
            //             'sueldo_diario'  => $historial->sueldo_diario,
            //             'sueldo_mensual' => $historial->sueldo_mensual,
            //             'sueldo_anual'   => $historial->sueldo_anual,
            //             'comentarios'    => $historial->comentarios,
            //             'created_at'     => $historial->created_at ? $historial->created_at->format('d/m/Y H:i:s') : null,
            //         ];
            //     }) : [],

            // 'departamento_historial' => $this->departamentosHistorial->map(function ($historial) {
            //     return [
            //         'id'              => $historial->id,
            //         'departamento_id' => $historial->departamento_id,
            //         'departamento'    => $historial->departamento ? $historial->departamento->nombre : null,
            //         'fecha_inicio'    => $historial->fecha_inicio ? Carbon::parse($historial->fecha_inicio)->format('d/m/Y') : null,
            //         'fecha_fin'       => $historial->fecha_fin ? Carbon::parse($historial->fecha_fin)->format('d/m/Y') : null,
            //         'comentarios'     => $historial->comentarios,
            //     ];
            // }),


            // Relación: Departamento
            // 'departamento' => $this->departamento ? [
            //     'id' => $this->departamento->id,
            //     'nombre' => $this->departamento->nombre,
            // ] : null,
            // 'vacaciones' => $this->vacaciones->map(function ($vacacion) {
            //     return [
            //         'id'               => $vacacion->id,
            //         'anio'             => $vacacion->anio,
            //         'dias_totales'     => $vacacion->dias_totales,
            //         'dias_disfrutados' => $vacacion->dias_disfrutados,
            //         'dias_disponibles' => $vacacion->dias_totales - $vacacion->dias_disfrutados,
            //         'historial'        => $vacacion->historial->map(function ($hist) {
            //             return [
            //                 'id'           => $hist->id,
            //                 'fecha_inicio' => $hist->fecha_inicio ? Carbon::parse($hist->fecha_inicio)->format('d/m/Y') : null,
            //                 'fecha_fin'    => $hist->fecha_fin ? Carbon::parse($hist->fecha_fin)->format('d/m/Y') : null,
            //                 'dias'         => $hist->dias,
            //                 'comentarios'  => $hist->comentarios,
            //                 'created_at'   => $hist->created_at ? $hist->created_at->format('d/m/Y H:i:s') : null,
            //             ];
            //         }),
            //     ];
            // }),


            // 'asistencias' => $this->asistencias->map(function ($asistencia) {
            //     return [
            //         'id'           => $asistencia->id,
            //         'user_id'      => $asistencia->user_id,
            //         'turno'        => $asistencia->turno ? [
            //             'id'     => $asistencia->turno->id,
            //             'nombre' => $asistencia->turno->nombre,
            //             'hora_inicio' => $asistencia->turno->hora_inicio,
            //             'hora_fin' => $asistencia->turno->hora_fin,
            //         ] : null,
            //         'fecha'        => $asistencia->fecha ? Carbon::parse($asistencia->fecha)->format('d/m/Y') : null,
            //         'hora_entrada' => $asistencia->hora_entrada,
            //         'hora_salida'  => $asistencia->hora_salida,
            //         'created_at'   => $asistencia->created_at ? $asistencia->created_at->format('d/m/Y H:i:s') : null,
            //     ];
            // }),



            // Relación: ModelHasStatus (todos los registros)
            // 'MODEL_HAS_STATUSES' => $this->modelHasStatuses->map(function ($mhs) {
            //     return [
            //         'id'          => $mhs->id,
            //         'nombre'      => $mhs->nombre,          // nombre específico de este registro
            //         'status_id'   => $mhs->status_id,
            //         'status_nombre' => $mhs->status ? $mhs->status->nombre : null, // nombre del status real
            //         'user_id'     => $mhs->user_id,
            //     ];
            // }),


            // 'notificaciones' => $this->notificaciones->map(function ($notif) {
            //     return [
            //         'id'          => $notif->id,
            //         'titulo'      => $notif->titulo,
            //         'mensaje'     => $notif->mensaje,
            //         'leido'       => $notif->leido,
            //         'fecha_envio' => $notif->fecha_envio ? Carbon::parse($notif->fecha_envio)->format('d/m/Y H:i:s') : null,
            //     ];
            // }),


            // 'bonos' => $this->bonos->map(function ($bono) {
            //     return [
            //         'id'       => $bono->id,
            //         'monto'    => $bono->monto,
            //         'concepto' => $bono->concepto,
            //         'fecha'    => $bono->fecha ? Carbon::parse($bono->fecha)->format('d/m/Y') : null,
            //     ];
            // }),


            // 'tiempos_extra' => $this->tiemposExtra->map(function ($tiempo) {
            //     return [
            //         'id'          => $tiempo->id,
            //         'fecha'       => $tiempo->fecha ? Carbon::parse($tiempo->fecha)->format('d/m/Y') : null,
            //         'hora_inicio' => $tiempo->hora_inicio,
            //         'hora_fin'    => $tiempo->hora_fin,
            //         'horas'       => $tiempo->horas,
            //         'motivo'      => $tiempo->motivo,
            //     ];
            // }),

            // 'turno' => $this->turno ? [
            //     'id'          => $this->turno->id,
            //     'nombre'      => $this->turno->nombre,
            //     'hora_inicio' => $this->turno->hora_inicio,
            //     'hora_fin'    => $this->turno->hora_fin,
            //     'descripcion' => $this->turno->descripcion,
            // ] : null,

            // 'password_resets' => $this->passwordResets->map(function ($reset) {
            //     return [
            //         'id'         => $reset->id,
            //         'email'      => $reset->email,
            //         'token'      => $reset->token,
            //         'created_at' => $reset->created_at ? Carbon::parse($reset->created_at)->format('d/m/Y H:i:s') : null,
            //     ];
            // }),
            // 'password_resets' => $this->passwordResets->map(function ($reset) {
            //     return [
            //         'id'         => $reset->id,
            //         'email'      => $reset->email,
            //         'token'      => $reset->token,
            //         'created_at' => $reset->created_at ? Carbon::parse($reset->created_at)->format('d/m/Y H:i:s') : null,
            //     ];
            // }),




            // 🔥 DEPTO dinámico desde Firebird
            'departamento' => $depto ? [
                'id'     => $depto->CLAVE,
                'nombre' => $depto->NOMBRE,
            ] : null,

            // WorkOrders solicitadas por el usuario
            // 'workorders_solicitadas' => $this->workordersSolicitadas->map(function ($wo) {
            //     return [
            //         'id'               => $wo->id,
            //         'titulo'           => $wo->titulo,
            //         'descripcion'      => $wo->descripcion,
            //         'status_id'        => $wo->status_id,
            //         'status'           => $wo->status ? $wo->status->nombre : null,
            //         'fecha_solicitud'  => $wo->fecha_solicitud ? Carbon::parse($wo->fecha_solicitud)->format('d/m/Y H:i:s') : null,
            //         'fecha_aprobacion' => $wo->fecha_aprobacion ? Carbon::parse($wo->fecha_aprobacion)->format('d/m/Y H:i:s') : null,
            //         'fecha_cierre'     => $wo->fecha_cierre ? Carbon::parse($wo->fecha_cierre)->format('d/m/Y H:i:s') : null,
            //     ];
            // }),

            // WorkOrders donde el usuario es aprobador
            // 'workorders_aprobadas' => $this->workordersAprobadas->map(function ($wo) {
            //     return [
            //         'id'               => $wo->id,
            //         'titulo'           => $wo->titulo,
            //         'descripcion'      => $wo->descripcion,
            //         'status_id'        => $wo->status_id,
            //         'status'           => $wo->status ? $wo->status->nombre : null,
            //         'solicitante'      => $wo->solicitante ? $wo->solicitante->nombre : null,
            //         'fecha_solicitud'  => $wo->fecha_solicitud ? Carbon::parse($wo->fecha_solicitud)->format('d/m/Y H:i:s') : null,
            //         'fecha_aprobacion' => $wo->fecha_aprobacion ? Carbon::parse($wo->fecha_aprobacion)->format('d/m/Y H:i:s') : null,
            //         'fecha_cierre'     => $wo->fecha_cierre ? Carbon::parse($wo->fecha_cierre)->format('d/m/Y H:i:s') : null,
            //     ];
            // }),

        ];
    }
}
//photos/photo_100_1764942680.png
//photos/photo_100_1764942680.png