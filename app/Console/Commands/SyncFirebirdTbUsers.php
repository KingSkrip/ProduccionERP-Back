<?php

namespace App\Console\Commands;

use App\Services\FirebirdComandEmpresaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncFirebirdTbUsers extends Command
{
    protected $signature = 'firebird:sync-tb-users {empresa : Número de empresa (01, 02, 03, etc.)} {--vincular-existentes : Vincular usuarios existentes sin pivote}';

    protected $description = 'Sincroniza TB (empleados activos) con USUARIOS Firebird y pivote MySQL';

    public function handle()
    {
        // 🏢 AQUÍ PONDRÁS EL NÚMERO DE EMPRESA MANUALMENTE
        $empresa = str_pad($this->argument('empresa'), 2, '0', STR_PAD_LEFT);

        $this->info("🔥 Iniciando sincronización para empresa: {$empresa}");

        try {
            // 🔌 Inicializar servicio con empresa específica
            $firebirdService = new FirebirdComandEmpresaService($empresa);

            // 📊 Obtener tabla TB más reciente
            $this->info("📊 Buscando tabla TB activa...");
            $tbResult = $firebirdService->getOperationalTable('TB');

            if ($tbResult['data']->isEmpty()) {
                $this->error("❌ No se encontró tabla TB activa para empresa {$empresa}");
                return 1;
            }

            $tbTabla = $tbResult['table'];
            $this->info("✅ Tabla encontrada: {$tbTabla}");

            // 🔍 Filtrar solo empleados activos (sin fecha de baja)
            $empleadosActivos = $tbResult['data']->filter(function ($empleado) {
                return empty($empleado->FECH_BAJA) ||
                    trim($empleado->FECH_BAJA) === '' ||
                    is_null($empleado->FECH_BAJA);
            });

            $this->info("👥 Empleados activos encontrados: " . $empleadosActivos->count());

            // 📝 Obtener todos los usuarios existentes en Firebird USUARIOS
            // ⚠️ USUARIOS está en srvasp01old, NO en SRVNOIxx
            $usuariosExistentes = $this->getUsuariosFromProduccion();

            // 📌 Obtener pivotes existentes para esta empresa
            $pivotesExistentes = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_empresa', $empresa)
                ->where('firebird_tb_tabla', $tbTabla)
                ->get()
                ->keyBy('firebird_tb_clave');

            // 🔗 Si se activa la opción, vincular usuarios existentes primero
            if ($this->option('vincular-existentes')) {
                $this->newLine();
                $this->info("🔗 VINCULANDO USUARIOS EXISTENTES SIN PIVOTE...");
                $this->vincularUsuariosExistentes(
                    $empleadosActivos,
                    $usuariosExistentes,
                    $pivotesExistentes,
                    $tbTabla,
                    $empresa
                );
                $this->info("✅ Vinculación completada");
                $this->newLine();
            }

            $procesados = 0;
            $nuevos = 0;
            $omitidos = 0;

            foreach ($empleadosActivos as $empleado) {
                $procesados++;

                // 🔍 Construir nombre completo
                $nombreCompleto = trim(
                    ($empleado->NOMBRE ?? '') . ' ' .
                        ($empleado->AP_PAT_ ?? '') . ' ' .
                        ($empleado->AP_MAT_ ?? '')
                );

                $correo = trim($empleado->EMAIL ?? '');
                $status = trim($empleado->STATUS ?? '');
                $depto = $empleado->DEPTO ?? 0;

                // ✅ Validar que tenga nombre Y CORREO (obligatorios para login)
                if (empty($nombreCompleto) || strlen($nombreCompleto) < 3) {
                    $this->warn("⚠️ Empleado TB CLAVE {$empleado->CLAVE} sin nombre válido, omitido");
                    $omitidos++;
                    continue;
                }

                if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("⚠️ Empleado TB CLAVE {$empleado->CLAVE} ({$nombreCompleto}) sin correo válido, omitido");
                    $omitidos++;
                    continue;
                }

                // 🔎 VERIFICAR SI YA EXISTE PIVOTE PARA ESTE EMPLEADO EN ESTA EMPRESA
                if ($pivotesExistentes->has($empleado->CLAVE)) {
                    $this->info("⏭️  Ya existe pivote para: {$nombreCompleto} (TB CLAVE: {$empleado->CLAVE})");
                    $omitidos++;
                    continue;
                }

                // 🔎 Buscar si ya existe usuario en Firebird con mismo CORREO
                // El correo es único, usamos eso como validación principal
                $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo) {
                    return strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo);
                });

                if ($usuarioExistente) {
                    $this->info("✅ Usuario ya existe en Firebird: {$nombreCompleto} (ID: {$usuarioExistente->ID})");

                    // 📌 Solo crear pivote (el usuario ya existe)
                    $identityId = $this->registrarPivote(
                        $usuarioExistente->ID,  // ✅ Pasa ID, no CLAVE
                        $empleado->CLAVE,
                        $tbTabla,
                        $empresa
                    );

                    if ($identityId) {
                        $this->asignarRol($identityId, $nombreCompleto);
                    }

                    $omitidos++;
                    continue;
                }

                // 🆕 Crear nuevo usuario en Firebird USUARIOS
                $this->info("➕ Creando usuario: {$nombreCompleto}");

                $passwordPlain = $this->generarPassword($nombreCompleto, $status);
                $nuevoId = $this->crearUsuarioFirebird(  // ✅ Cambiado nombre variable
                    $nombreCompleto,
                    $correo,
                    $passwordPlain,
                    $depto
                );

                if ($nuevoId) {  // ✅ Cambiado
                    $this->info("✅ Usuario creado con ID: {$nuevoId}");  // ✅ Cambiado
                    $this->info("🔐 Password: {$passwordPlain}");

                    // 📌 Registrar en pivote MySQL
                    $identityId = $this->registrarPivote($nuevoId, $empleado->CLAVE, $tbTabla, $empresa);  // ✅ Pasa ID

                    if ($identityId) {
                        $this->asignarRol($identityId, $nombreCompleto);
                    }

                    $nuevos++;
                } else {
                    $this->error("❌ Error al crear usuario: {$nombreCompleto}");
                }
            }

            // 📊 Resumen
            $this->newLine();
            $this->info("🎯 RESUMEN DE SINCRONIZACIÓN - Empresa {$empresa}");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📋 Total procesados: {$procesados}");
            $this->info("✅ Nuevos usuarios: {$nuevos}");
            $this->info("⏭️  Omitidos (ya existían): {$omitidos}");
            $this->info("🗄️  Tabla TB: {$tbTabla}");

            return 0;
        } catch (\Exception $e) {
            $this->error("💥 Error fatal: " . $e->getMessage());
            Log::error('Error en sync TB users', [
                'empresa' => $empresa,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 🔗 Vincula usuarios que YA existen en USUARIOS pero NO tienen pivote
     * Útil para sincronizar usuarios creados manualmente antes del comando
     */
    protected function vincularUsuariosExistentes(
        $empleadosActivos,
        $usuariosExistentes,
        $pivotesExistentes,
        string $tbTabla,
        string $empresa
    ): void {
        $vinculados = 0;
        $noEncontrados = 0;

        foreach ($empleadosActivos as $empleado) {
            // Si ya tiene pivote, skip
            if ($pivotesExistentes->has($empleado->CLAVE)) {
                continue;
            }

            $nombreCompleto = trim(
                ($empleado->NOMBRE ?? '') . ' ' .
                    ($empleado->AP_PAT_ ?? '') . ' ' .
                    ($empleado->AP_MAT_ ?? '')
            );
            $correo = trim($empleado->EMAIL ?? '');

            // Validar correo
            if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // 🔍 Buscar usuario existente por CORREO o NOMBRE
            $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo, $nombreCompleto) {
                $correoMatch = strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo);
                $nombreMatch = strtoupper(trim($usuario->NOMBRE ?? '')) === strtoupper($nombreCompleto);

                // Buscar por correo O por nombre completo
                return $correoMatch || $nombreMatch;
            });

            if ($usuarioExistente) {
                $this->info("🔗 Vinculando: {$nombreCompleto} (FB CLAVE: {$usuarioExistente->ID} ↔ TB: {$empleado->CLAVE})");

                // Crear pivote
                $identityId = $this->registrarPivote(
                    $usuarioExistente->ID,
                    $empleado->CLAVE,
                    $tbTabla,
                    $empresa
                );

                if ($identityId) {
                    // Asignar rol si no tiene
                    $this->asignarRol($identityId, $nombreCompleto);
                    $vinculados++;
                }
            } else {
                $noEncontrados++;
            }
        }

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("🔗 Usuarios vinculados: {$vinculados}");
        $this->info("⚠️  No encontrados en USUARIOS: {$noEncontrados}");
    }

    /**
     * 🔌 Conexión a Firebird PRODUCCIÓN (srvasp01old) para USUARIOS
     */
    protected function getProduccionConnection()
    {
        config([
            'database.connections.firebird_produccion' => [
                'driver'   => 'firebird',
                'host'     => env('FB_HOST'),
                'port'     => env('FB_PORT'),
                'database' => env('FB_DATABASE'), // srvasp01old
                'username' => env('FB_USERNAME'),
                'password' => env('FB_PASSWORD'),
                'charset'  => env('FB_CHARSET', 'UTF8'),
                'dialect'  => 3,
                'quote_identifiers' => false,
            ]
        ]);

        DB::purge('firebird_produccion');
        return DB::connection('firebird_produccion');
    }

    /**
     * 📋 Obtener usuarios de tabla USUARIOS (en srvasp01old)
     */
    protected function getUsuariosFromProduccion()
    {
        return collect(
            $this->getProduccionConnection()->select("SELECT * FROM USUARIOS")
        );
    }

    /**
     * 🔐 Genera contraseña: primeras letras + status
     * Ejemplo: JUAN PANCHO LOPEZ DE LA BARRERA + status A = JPLOPABA
     */
    protected function generarPassword(string $nombreCompleto, string $status): string
    {
        $palabras = preg_split('/\s+/', strtoupper(trim($nombreCompleto)));

        // Filtrar palabras de menos de 3 letras (DE, LA, etc.)
        $palabras = array_filter($palabras, function ($palabra) {
            return strlen($palabra) >= 3;
        });

        $iniciales = '';
        foreach ($palabras as $palabra) {
            // Tomar primera letra de cada palabra significativa
            $iniciales .= substr($palabra, 0, 1);

            // Tomar segunda letra solo de palabras largas (>4 chars)
            if (strlen($palabra) > 4) {
                $iniciales .= substr($palabra, 1, 1);
            }
        }

        return $iniciales . strtoupper($status);
    }

    /**
     * ➕ Crea usuario en tabla USUARIOS de Firebird (srvasp01old)
     * Retorna el ID generado por el trigger
     */
    protected function crearUsuarioFirebird(string $nombre, string $correo, string $passwordPlain, int $depto): ?int
    {
        try {
            $connection = $this->getProduccionConnection();

            // 🔑 Obtener siguiente CLAVE
            $maxClave = $connection->selectOne("SELECT MAX(CLAVE) as MAX_CLAVE FROM USUARIOS");
            $nuevaClave = ($maxClave->MAX_CLAVE ?? 0) + 1;

            // 🔐 Hash de contraseña (Laravel Bcrypt)
            $passwordHash = Hash::make($passwordPlain);

            // 📝 Insertar en USUARIOS (ID se genera automáticamente por el trigger)
            $connection->insert("
            INSERT INTO USUARIOS (
                CLAVE,
                NOMBRE,
                CORREO,
                PASSWORD2,
                PHOTO,
                DEPTO,
                STATUS,
                FECHAACT,
                SESIONES,
                PERFIL
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
                $nuevaClave,
                strtoupper(substr($nombre, 0, 31)),
                strtolower(substr($correo, 0, 37)),
                $passwordHash,
                'photos/users.jpg',
                $depto,
                1,
                Carbon::now()->format('Y-m-d H:i:s'),
                0,
                0
            ]);

            // 🔍 Obtener el ID generado por el trigger consultando por CLAVE
            $usuario = $connection->selectOne("SELECT ID FROM USUARIOS WHERE CLAVE = ?", [$nuevaClave]);

            return $usuario->ID ?? null;  // ✅ Retorna ID, no CLAVE

        } catch (\Exception $e) {
            Log::error('Error al crear usuario Firebird', [
                'nombre' => $nombre,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 📌 Registra relación en tabla pivote MySQL
     * Retorna el ID del registro creado para usarlo en model_has_roles
     */
    protected function registrarPivote(int $firebirdUserId, int $firebirdTbClave, string $tbTabla, string $empresa): ?int
    {
        try {
            // 🔍 Verificar si ya existe
            $existe = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_empresa', $empresa)
                ->where('firebird_user_clave', $firebirdUserId)
                ->where('firebird_tb_tabla', $tbTabla)
                ->where('firebird_tb_clave', $firebirdTbClave)
                ->first();

            if ($existe) {
                $this->info("📌 Pivote ya existe, usando ID: {$existe->id}");
                return $existe->id;
            }

            // ➕ Insertar nuevo pivote
            $id = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->insertGetId([
                    'firebird_user_clave' => $firebirdUserId,
                    'firebird_tb_clave' => $firebirdTbClave,
                    'firebird_tb_tabla' => $tbTabla,
                    'firebird_empresa' => $empresa,
                    'firebird_clie_clave' => null,
                    'firebird_clie_tabla' => null,
                    'firebird_vend_clave' => null,
                    'firebird_vend_clave' => null,
                    'created_at' => Carbon::now()
                ]);

            $this->info("📌 Pivote creado con ID: {$id}");
            return $id;
        } catch (\Exception $e) {
            Log::error('Error al registrar pivote', [
                'fb_user_id' => $firebirdUserId,
                'tb_clave' => $firebirdTbClave,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 🎭 Asigna rol en model_has_roles
     * role_id = 3 si es Angel Daniel Peñaloza Vallejo
     * role_id = 1 para todos los demás
     */
    protected function asignarRol(int $firebirdIdentityId, string $nombreCompleto): void
    {
        try {
            // 🔍 Verificar si ya tiene rol asignado
            $yaTieneRol = DB::connection('mysql')
                ->table('model_has_roles')
                ->where('firebird_identity_id', $firebirdIdentityId)
                ->exists();

            if ($yaTieneRol) {
                $this->info("🎭 Usuario ya tiene rol asignado");
                return;
            }

            // 🎯 Determinar rol según el nombre
            $nombreUpper = strtoupper(trim($nombreCompleto));
            $roleId = (strpos($nombreUpper, 'ANGEL DANIEL') !== false &&
                strpos($nombreUpper, 'PEÑALOZA') !== false &&
                strpos($nombreUpper, 'VALLEJO') !== false) ? 3 : 1;

            // ➕ Insertar rol
            DB::connection('mysql')
                ->table('model_has_roles')
                ->insert([
                    'role_id' => $roleId,
                    'subrol_id' => null,
                    'firebird_identity_id' => $firebirdIdentityId,
                    'model_type' => 'firebird_identity',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);

            $roleName = $roleId === 3 ? 'ADMIN' : 'USUARIO';
            $this->info("🎭 Rol asignado: {$roleName} (role_id: {$roleId})");
        } catch (\Exception $e) {
            Log::error('Error al asignar rol', [
                'identity_id' => $firebirdIdentityId,
                'nombre' => $nombreCompleto,
                'error' => $e->getMessage()
            ]);
        }
    }
}
