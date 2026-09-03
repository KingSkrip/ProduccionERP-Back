<?php

namespace App\Console\Commands;

use App\Services\FirebirdComandEmpresaService;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateNumeroCredencial extends Command
{
    /**
     * 🧩 Uso:
     *  php artisan firebird:generate-numero-credencial 04
     *  php artisan firebird:generate-numero-credencial 04 --dry-run
     *  php artisan firebird:generate-numero-credencial 04 --force
     */
    protected $signature = 'firebird:generate-numero-credencial
        {empresa : Número de empresa (01, 02, 03, 04, 05, etc.)}
        {--dry-run : Solo mostrar el resultado sin guardar nada en MySQL}
        {--force : Sobreescribir numero_credencial aunque ya tenga valor}
        {--incluir-inactivos : Procesar también empleados dados de baja (FECH_BAJA lleno). Por default solo se procesan activos}
        {--export= : Ruta de archivo CSV donde exportar los "sin match" y "sin pivote" (ej. storage/app/reporte_credenciales_01.csv)}';

    protected $description = 'Genera NUMERO_CREDENCIAL cruzando TB (Firebird) -> USUARIOS (Firebird) -> user_firebird_identities (MySQL)';

    protected FirebirdConnectionService $firebirdService;

    /**
     * ✅ Statuses de TB que se consideran "empleado activo" para efectos de credencial.
     *    Los tres tienen el MISMO peso/prioridad entre sí; solo B (o cualquier otro
     *    valor) se considera inválido y nunca se usa.
     */
    protected const STATUS_ACTIVOS = ['A', 'R', 'I'];

    /**
     * 🗄️ Caché en memoria de tablas TB por empresa, para no volver a consultar
     * Firebird por cada empleado cuando necesitamos el status de otra empresa.
     * Estructura: ['04' => Collection keyBy CLAVE, ...]
     */
    protected array $tbCache = [];

    public function __construct(FirebirdConnectionService $firebirdService)
    {
        parent::__construct();
        $this->firebirdService = $firebirdService;
    }

    /**
     * 🏷️ Obtiene el STATUS (A/B/R) de un empleado en la tabla TB de una empresa específica.
     * El campo vive en Firebird (tabla TB), NO en MySQL. Usa caché para no repetir
     * la consulta de la tabla TB completa por cada empleado.
     */
    protected function getEstatusTb(string $empresaCode, string $claveTb): ?string
    {
        $empresaCode = str_pad($empresaCode, 2, '0', STR_PAD_LEFT);

        if (! isset($this->tbCache[$empresaCode])) {
            try {
                $empresaService = app(FirebirdEmpresaService::class);
                $empresaService->setEmpresa($empresaCode);

                $firebirdComandService = new FirebirdComandEmpresaService(
                    $empresaService,
                    $this->firebirdService
                );

                $tbResult = $firebirdComandService->getOperationalTable('TB');

                $this->tbCache[$empresaCode] = $tbResult['data']->keyBy(
                    fn ($row) => trim((string) ($row->CLAVE ?? ''))
                );
            } catch (\Throwable $e) {
                $this->warn("⚠️ No se pudo cargar tabla TB de empresa {$empresaCode} para checar status: ".$e->getMessage());
                $this->tbCache[$empresaCode] = collect();
            }
        }

        $row = $this->tbCache[$empresaCode]->get(trim($claveTb));

        if (! $row) {
            return null;
        }

        // 👇 Si en tu Firebird el campo se llama distinto (ej. ESTATUS), avísame y lo ajusto.
        return strtoupper(trim((string) ($row->STATUS ?? $row->ESTATUS ?? '')));
    }

    public function handle()
    {
        $empresa = str_pad($this->argument('empresa'), 2, '0', STR_PAD_LEFT);
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info("🔥 Generando NUMERO_CREDENCIAL para empresa: {$empresa}".($dryRun ? ' (DRY-RUN, no se guardará nada)' : ''));

        try {
            // 🔌 Servicio para consultar TB de la empresa indicada
            $empresaService = app(FirebirdEmpresaService::class);
            $empresaService->setEmpresa($empresa);

            $firebirdComandService = new FirebirdComandEmpresaService(
                $empresaService,
                $this->firebirdService
            );

            // 📊 Obtener tabla TB de esa empresa (ej. TB19072604)
            $this->info('📊 Buscando tabla TB activa...');
            $tbResult = $firebirdComandService->getOperationalTable('TB');

            if ($tbResult['data']->isEmpty()) {
                $this->error("❌ No se encontró tabla TB activa para empresa {$empresa}");

                return 1;
            }

            $tbTabla = $tbResult['table'];
            $this->info("✅ Tabla encontrada: {$tbTabla}");

            // 👥 USUARIOS vive en srvasp01old (producción), NO en SRVNOIxx
            $this->info('👥 Obteniendo tabla USUARIOS de Firebird producción...');
            $usuariosFirebird = collect(
                $this->firebirdService->getProductionConnection()->select(
                    'SELECT ID, NOMBRE, CORREO FROM USUARIOS WHERE RP_WEB = 1'
                )
            );

            $procesados = 0;
            $actualizados = 0;
            $sinNombre = 0;
            $sinMatchUsuario = 0;
            $sinPivote = 0;
            $yaTenia = 0;
            $errores = 0;
            $migrados = 0; // 🔁 pivote encontrado en otra empresa/TB distinta a la del loop actual (sin tocar el registro)
            $reasignados = 0; // 🔀 pivote viejo (status B en otra empresa) reasignado a la empresa/TB actual

            foreach ($tbResult['data'] as $empleadoTb) {
                $procesados++;

                $tbClave = trim((string) ($empleadoTb->CLAVE ?? ''));

                if ($tbClave === '') {
                    $this->warn('⚠️ Fila TB sin CLAVE, omitida');

                    continue;
                }

                // 🧾 Nombre completo TB: NOMBRE + AP_PAT_ + AP_MAT_ (mismo orden que en el sync)
                $nombreCompleto = trim(
                    ($empleadoTb->NOMBRE ?? '').' '.
                    ($empleadoTb->AP_PAT_ ?? '').' '.
                    ($empleadoTb->AP_MAT_ ?? '')
                );

                if (empty($nombreCompleto) || strlen($nombreCompleto) < 3) {
                    $this->warn("⚠️ TB CLAVE {$tbClave} sin nombre válido, omitida");
                    $sinNombre++;

                    continue;
                }

                // 🏷️ Status del empleado en el TB que se está procesando AHORA (empresa/clave actuales)
                $statusActual = strtoupper(trim((string) ($empleadoTb->STATUS ?? $empleadoTb->ESTATUS ?? '')));

                // 🔎 1) Buscar en USUARIOS (Firebird). Preferimos CORREO porque es único;
                //    NOMBRE puede repetirse (ej. cuentas genéricas/administrativas con el
                //    mismo nombre que un empleado real) y agarrar la fila equivocada rompe
                //    el resto del flujo (parece "sin pivote" cuando en realidad sí existe).
                $correoEmpleado = strtolower(trim($empleadoTb->EMAIL ?? ''));
                $nombreCompletoUpper = strtoupper($nombreCompleto);

                $usuarioFirebird = null;

                if ($correoEmpleado !== '') {
                    $usuarioFirebird = $usuariosFirebird->first(function ($usuario) use ($correoEmpleado) {
                        return strtolower(trim($usuario->CORREO ?? '')) === $correoEmpleado;
                    });
                }

                if (! $usuarioFirebird) {
                    $candidatosNombre = $usuariosFirebird->filter(function ($usuario) use ($nombreCompletoUpper) {
                        return strtoupper(trim($usuario->NOMBRE ?? '')) === $nombreCompletoUpper;
                    });

                    if ($candidatosNombre->count() > 1) {
                        // 🧩 NOMBRE duplicado en USUARIOS: preferimos el candidato que ya
                        //    tiene pivote en MySQL (más probable que sea el correcto) en vez
                        //    de tomar simplemente el primero que aparezca.
                        $usuarioFirebird = $candidatosNombre->first(function ($usuario) {
                            return DB::connection('mysql')
                                ->table('users_firebird_identities')
                                ->where('firebird_user_clave', $usuario->ID)
                                ->exists();
                        }) ?? $candidatosNombre->first();

                        $this->warn("⚠️ NOMBRE duplicado en USUARIOS para: {$nombreCompleto} (IDs: {$candidatosNombre->pluck('ID')->implode(', ')}), se eligió ID {$usuarioFirebird->ID}");
                        Log::warning('🧯 CREDENCIAL_NOMBRE_DUPLICADO', [
                            'nombre_completo' => $nombreCompleto,
                            'tb_clave' => $tbClave,
                            'ids_candidatos' => $candidatosNombre->pluck('ID')->values(),
                            'id_elegido' => $usuarioFirebird->ID,
                        ]);
                    } else {
                        $usuarioFirebird = $candidatosNombre->first();
                    }
                }

                if (! $usuarioFirebird) {
                    $this->warn("⚠️ Sin match en USUARIOS para: {$nombreCompleto} (TB CLAVE: {$tbClave})");
                    Log::warning('🧯 CREDENCIAL_SIN_MATCH_USUARIO', [
                        'empresa' => $empresa,
                        'tb_tabla' => $tbTabla,
                        'tb_clave' => $tbClave,
                        'nombre_completo' => $nombreCompleto,
                    ]);
                    $sinMatchUsuario++;

                    continue;
                }

                $firebirdUserId = (int) $usuarioFirebird->ID;

                // 🔎 2) Buscar TODOS los pivotes del usuario en MySQL (sin filtrar por tb_clave/empresa).
                //     Un mismo empleado puede tener registros en varias empresas/TB con distinto status
                //     (ej. status B en empresa 4 y status A/R vigente en otra empresa).
                $identities = DB::connection('mysql')
                    ->table('users_firebird_identities')
                    ->where('firebird_user_clave', $firebirdUserId)
                    ->get();

                if ($identities->isEmpty()) {
                    $this->warn("⚠️ Sin pivote en users_firebird_identities para: {$nombreCompleto} (FB ID: {$firebirdUserId} / TB CLAVE: {$tbClave})");
                    Log::warning('🧯 CREDENCIAL_SIN_PIVOTE', [
                        'firebird_user_id' => $firebirdUserId,
                        'tb_clave' => $tbClave,
                        'nombre_completo' => $nombreCompleto,
                    ]);
                    $sinPivote++;

                    continue;
                }

                // 🏷️ El status real vive en Firebird (tabla TB de cada empresa), NO en MySQL.
                //    Para cada pivote candidato, resolvemos su status: si es de la empresa/TB
                //    que ya trajimos en este loop usamos $empleadoTb directo (evita otra consulta);
                //    si es de otra empresa, lo buscamos (con caché) en la TB de esa empresa.
                $candidatos = $identities->map(function ($i) use ($tbClave, $empresa, $statusActual) {
                    $empresaId = ! empty($i->firebird_empresa)
                        ? str_pad(trim((string) $i->firebird_empresa), 2, '0', STR_PAD_LEFT)
                        : $empresa;

                    $claveId = (string) $i->firebird_tb_clave;

                    $esEmpresaActual = $empresaId === $empresa && $claveId === $tbClave;

                    $status = $esEmpresaActual
                        ? $statusActual
                        : (string) ($this->getEstatusTb($empresaId, $claveId) ?? '');

                    return (object) [
                        'identity' => $i,
                        'empresa' => $empresaId,
                        'clave' => $claveId,
                        'esEmpresaActual' => $esEmpresaActual,
                        'status' => $status,
                    ];
                });

                // 🥇 Prioridad de selección:
                //    1) TB/empresa actual con status A, R o I (mismo peso entre los tres)
                //    2) Cualquier otra empresa con status A, R o I (mismo peso entre los tres)
                //    Los de status B (o cualquier otro valor) nunca se usan.
                $elegido = $candidatos->first(fn ($c) => $c->esEmpresaActual && in_array($c->status, self::STATUS_ACTIVOS, true))
                    ?? $candidatos->first(fn ($c) => in_array($c->status, self::STATUS_ACTIVOS, true));

                // 🔀 CASO NUEVO: ningún pivote existente tiene status A/R (todos en otra empresa
                //    con status B, por ejemplo), PERO el empleado SÍ está activo (A/R) en el TB
                //    que se está procesando ahora mismo, y todavía no existe un pivote apuntando
                //    a esta empresa/clave. En ese caso reasignamos el pivote más reciente del
                //    usuario para que ahora apunte aquí, en vez de dejarlo casado con la empresa vieja.
                $reasignoEsteEmpleado = false;

                $existeCandidatoEmpresaActual = $candidatos->contains(fn ($c) => $c->esEmpresaActual);

                if (! $elegido && ! $existeCandidatoEmpresaActual && in_array($statusActual, self::STATUS_ACTIVOS, true)) {
                    $identityAReasignar = $identities->sortByDesc('id')->first();

                    if ($identityAReasignar) {
                        $this->warn("🔀 {$nombreCompleto}: sin status A/R/I en ningún pivote existente, pero está activo (status {$statusActual}) en empresa {$empresa} / TB CLAVE {$tbClave}. Reasignando pivote ID {$identityAReasignar->id} (antes: empresa {$identityAReasignar->firebird_empresa} / TB CLAVE {$identityAReasignar->firebird_tb_clave}).");

                        if (! $dryRun) {
                            DB::connection('mysql')
                                ->table('users_firebird_identities')
                                ->where('id', $identityAReasignar->id)
                                ->update([
                                    'firebird_empresa' => $empresa,
                                    'firebird_tb_clave' => $tbClave,
                                    'firebird_tb_tabla' => $tbTabla,
                                ]);
                        }

                        // 🔧 Reflejamos el cambio en memoria para que el resto del flujo
                        //    (armado de NUMERO_CREDENCIAL, update de numero_credencial) trate
                        //    a este pivote como si ya apuntara aquí.
                        $identityAReasignar->firebird_empresa = $empresa;
                        $identityAReasignar->firebird_tb_clave = $tbClave;
                        $identityAReasignar->firebird_tb_tabla = $tbTabla;

                        $elegido = (object) [
                            'identity' => $identityAReasignar,
                            'empresa' => $empresa,
                            'clave' => $tbClave,
                            'esEmpresaActual' => true,
                            'status' => $statusActual,
                        ];

                        $reasignoEsteEmpleado = true;
                        $reasignados++;

                        Log::info('🔀 CREDENCIAL_PIVOTE_REASIGNADO', [
                            'firebird_user_id' => $firebirdUserId,
                            'identity_id' => $identityAReasignar->id,
                            'nueva_empresa' => $empresa,
                            'nueva_tb_clave' => $tbClave,
                            'nueva_tb_tabla' => $tbTabla,
                            'nombre_completo' => $nombreCompleto,
                            'dry_run' => $dryRun,
                        ]);
                    }
                }

                if (! $elegido) {
                    $this->warn("⚠️ Ningún pivote tiene status A/R/I para: {$nombreCompleto} (FB ID: {$firebirdUserId} / TB CLAVE: {$tbClave}), omitido");

                    // 🔬 DIAGNÓSTICO: por qué no calificó. Esto nos dice exactamente qué vio
                    // el código: el status actual calculado, si detectó (o no) un candidato ya
                    // apuntando a esta empresa/clave, y el detalle de cada pivote evaluado.
                    $this->line("   🔬 statusActual={$statusActual} | esActivo=".(in_array($statusActual, self::STATUS_ACTIVOS, true) ? 'SI' : 'NO').' | existeCandidatoEmpresaActual='.($existeCandidatoEmpresaActual ? 'SI' : 'NO'));
                    foreach ($candidatos as $c) {
                        $this->line("   🔬   pivote id={$c->identity->id} empresa={$c->empresa} clave={$c->clave} esEmpresaActual=".($c->esEmpresaActual ? 'SI' : 'NO')." status='{$c->status}'");
                    }

                    Log::warning('🧯 CREDENCIAL_SIN_STATUS_VALIDO_OMITIDO', [
                        'firebird_user_id' => $firebirdUserId,
                        'tb_clave' => $tbClave,
                        'nombre_completo' => $nombreCompleto,
                        'status_actual_tb' => $statusActual,
                        'existe_candidato_empresa_actual' => $existeCandidatoEmpresaActual,
                        'candidatos' => $candidatos->map(fn ($c) => ['identity_id' => $c->identity->id, 'empresa' => $c->empresa, 'clave' => $c->clave, 'esEmpresaActual' => $c->esEmpresaActual, 'status' => $c->status])->values(),
                    ]);
                    $sinPivote++;

                    continue;
                }

                $identity = $elegido->identity;

                // ℹ️ Si el pivote elegido es de otra empresa/TB distinta a la que se procesa en este loop
                //    (ej. estaba dado de baja en empresa 4 y ahora está activo en empresa 1/2/3/5), avisamos.
                //    (El caso reasignado ya avisó arriba, no lo repetimos aquí.)
                if (! $elegido->esEmpresaActual) {
                    $this->warn("🔁 {$nombreCompleto}: sin status A/R/I en TB CLAVE {$tbClave} (empresa {$empresa}), se usará su pivote vigente en empresa {$elegido->empresa} / TB CLAVE {$elegido->clave} (status {$elegido->status})");
                    $migrados++;
                }

                // 🔢 3) Armar NUMERO_CREDENCIAL: CLAVE TB del pivote elegido + EMPRESA del pivote elegido,
                //     rellenado con ceros a 10 dígitos. OJO: usamos la clave/empresa del pivote elegido,
                //     no necesariamente las del loop actual.
                $numeroCredencial = $this->armarNumeroCredencial($elegido->clave, $elegido->empresa);

                if (! empty($identity->numero_credencial) && ! $force && ! $reasignoEsteEmpleado) {
                    $this->info("⏭️  Ya tiene numero_credencial ({$identity->numero_credencial}): {$nombreCompleto}, omitido (usa --force para sobreescribir)");
                    $yaTenia++;

                    continue;
                }

                $this->info("🔢 {$nombreCompleto} -> NUMERO_CREDENCIAL: {$numeroCredencial}");

                if (! $dryRun) {
                    try {
                        DB::connection('mysql')
                            ->table('users_firebird_identities')
                            ->where('id', $identity->id)
                            ->update([
                                'numero_credencial' => $numeroCredencial,
                            ]);

                        $actualizados++;
                    } catch (\Throwable $e) {
                        $this->error("❌ Error guardando numero_credencial para {$nombreCompleto}: ".$e->getMessage());
                        Log::error('⚠️ CREDENCIAL_UPDATE_ERROR', [
                            'identity_id' => $identity->id,
                            'numero_credencial' => $numeroCredencial,
                            'error' => $e->getMessage(),
                        ]);
                        $errores++;
                    }
                } else {
                    $actualizados++; // contado como "se hubiera actualizado"
                }
            }

            // 📊 Resumen
            $this->newLine();
            $this->info("🎯 RESUMEN - Empresa {$empresa}".($dryRun ? ' (DRY-RUN)' : ''));
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info("📋 Total procesados (TB): {$procesados}");
            $this->info("✅ Actualizados: {$actualizados}");
            $this->info("🔁 Pivote tomado de otra empresa (migrados, sin tocar el registro): {$migrados}");
            $this->info("🔀 Pivote reasignado a la empresa/TB actual: {$reasignados}");
            $this->info("⏭️  Ya tenían numero_credencial: {$yaTenia}");
            $this->info("⚠️  Sin nombre válido: {$sinNombre}");
            $this->info("⚠️  Sin match en USUARIOS: {$sinMatchUsuario}");
            $this->info("⚠️  Sin pivote en users_firebird_identities (o solo status B): {$sinPivote}");
            $this->info("❌ Errores al guardar: {$errores}");
            $this->info("🗄️  Tabla TB: {$tbTabla}");

            return 0;
        } catch (\Throwable $e) {
            $this->error('💥 Error fatal: '.$e->getMessage());
            Log::error('Error en GenerateNumeroCredencial', [
                'empresa' => $empresa,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * 🔢 Arma el NUMERO_CREDENCIAL: CLAVE (TB) + EMPRESA, rellenado con ceros
     * a la izquierda hasta completar 10 dígitos.
     *
     * Ej: clave=156, empresa=04 -> "15604" -> "0000015604"
     */
    protected function armarNumeroCredencial(string $tbClave, string $empresa): string
    {
        $claveNum = trim($tbClave);
        $empresaNum = trim($empresa);

        $concatenado = $claveNum.$empresaNum;

        return str_pad($concatenado, 10, '0', STR_PAD_LEFT);
    }
}
