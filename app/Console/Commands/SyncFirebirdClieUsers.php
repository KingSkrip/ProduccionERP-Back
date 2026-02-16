<?php

namespace App\Console\Commands;

use App\Services\FirebirdComandEmpresaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncFirebirdClieUsers extends Command
{
    protected $signature = 'firebird:sync-clie-users {--vincular-existentes : Vincular usuarios existentes sin pivote} {--asignar-roles : Asignar roles faltantes a identidades sin rol}';

    protected $description = 'Sincroniza CLIE03 (clientes) con USUARIOS Firebird y pivote MySQL';

    public function handle()
    {
        $this->info("🔥 Iniciando sincronización de clientes CLIE03");

        try {
            // 🎭 Si se pide solo asignar roles, ejecutar esa función y salir
            if ($this->option('asignar-roles')) {
                $this->newLine();
                $this->info("🎭 ASIGNANDO ROLES FALTANTES...");
                $this->asignarRolesFaltantes();
                $this->info("✅ Asignación de roles completada");
                return 0;
            }

            // 📊 Obtener clientes de CLIE03
            $this->info("📊 Obteniendo clientes de CLIE03...");
            $clientes = $this->getClientesFromClie03();

            if ($clientes->isEmpty()) {
                $this->error("❌ No se encontraron clientes en CLIE03");
                return 1;
            }

            $this->info("👥 Clientes encontrados: " . $clientes->count());

            // 📝 Obtener todos los usuarios existentes en Firebird USUARIOS
            $usuariosExistentes = $this->getUsuariosFromProduccion();

            // 📌 Obtener pivotes existentes para CLIE03
            $pivotesExistentes = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_clie_tabla', 'CLIE03')
                ->whereNotNull('firebird_clie_clave')
                ->get()
                ->keyBy('firebird_clie_clave');

            // 🔗 Si se activa la opción, vincular usuarios existentes primero
            if ($this->option('vincular-existentes')) {
                $this->newLine();
                $this->info("🔗 VINCULANDO USUARIOS EXISTENTES SIN PIVOTE...");
                $this->vincularUsuariosExistentes(
                    $clientes,
                    $usuariosExistentes,
                    $pivotesExistentes
                );
                $this->info("✅ Vinculación completada");
                $this->newLine();
            }

            $procesados = 0;
            $nuevos = 0;
            $omitidos = 0;

            foreach ($clientes as $cliente) {
                $procesados++;

                $nombreCompleto = trim($cliente->NOMBRE ?? '');
                $correo = trim($cliente->EMAILPRED ?? '');
                $claveClie = $cliente->CLAVE ?? 0;

                // ✅ Validar que tenga nombre Y CORREO (obligatorios para login)
                if (empty($nombreCompleto) || strlen($nombreCompleto) < 3) {
                    $this->warn("⚠️ Cliente CLAVE {$claveClie} sin nombre válido, omitido");
                    $omitidos++;
                    continue;
                }

                if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("⚠️ Cliente CLAVE {$claveClie} ({$nombreCompleto}) sin correo válido, omitido");
                    $omitidos++;
                    continue;
                }

                // 🔎 VERIFICAR SI YA EXISTE PIVOTE PARA ESTE CLIENTE
                if ($pivotesExistentes->has($claveClie)) {
                    $this->info("⏭️  Ya existe pivote para: {$nombreCompleto} (CLIE CLAVE: {$claveClie})");
                    $omitidos++;
                    continue;
                }

                // 🔎 Buscar si ya existe usuario en Firebird con mismo CORREO
                $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo) {
                    return strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo);
                });

                if ($usuarioExistente) {
                    $this->info("✅ Usuario ya existe en Firebird: {$nombreCompleto} (ID: {$usuarioExistente->ID})");

                    // 📌 Solo crear pivote (el usuario ya existe)
                    $identityId = $this->registrarPivote(
                        $usuarioExistente->ID,
                        $claveClie
                    );

                    if ($identityId) {
                        $this->asignarRol($identityId, $nombreCompleto);
                    }

                    $omitidos++;
                    continue;
                }

                // 🆕 Crear nuevo usuario en Firebird USUARIOS
                $this->info("➕ Creando usuario: {$nombreCompleto}");

                $passwordPlain = $this->generarPassword($nombreCompleto);
                $nuevoId = $this->crearUsuarioFirebird(
                    $nombreCompleto,
                    $correo,
                    $passwordPlain
                );

                if ($nuevoId) {
                    $this->info("✅ Usuario creado con ID: {$nuevoId}");
                    $this->info("🔐 Password: {$passwordPlain}");

                    // 📌 Registrar en pivote MySQL
                    $identityId = $this->registrarPivote($nuevoId, $claveClie);

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
            $this->info("🎯 RESUMEN DE SINCRONIZACIÓN - CLIE03");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📋 Total procesados: {$procesados}");
            $this->info("✅ Nuevos usuarios: {$nuevos}");
            $this->info("⏭️  Omitidos (ya existían): {$omitidos}");
            $this->info("🗄️  Tabla: CLIE03");

            // 🎭 Asignar roles faltantes automáticamente al final
            $this->newLine();
            $this->info("🎭 Verificando roles faltantes...");
            $this->asignarRolesFaltantes();

            return 0;
        } catch (\Exception $e) {
            $this->error("💥 Error fatal: " . $e->getMessage());
            Log::error('Error en sync CLIE users', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 🎭 Asigna roles faltantes a todas las identidades de CLIE03 que no tienen rol
     */
    protected function asignarRolesFaltantes(): void
    {
        try {
            // 🔍 Obtener todas las identidades de CLIE03
            $identidadesClie = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_clie_tabla', 'CLIE03')
                ->whereNotNull('firebird_clie_clave')
                ->get();

            $this->info("📊 Identidades de CLIE03 encontradas: " . $identidadesClie->count());

            $rolesAsignados = 0;
            $yaTenianRol = 0;

            foreach ($identidadesClie as $identity) {
                // 🔍 Verificar si ya tiene rol
                $tieneRol = DB::connection('mysql')
                    ->table('model_has_roles')
                    ->where('firebird_identity_id', $identity->id)
                    ->exists();

                if ($tieneRol) {
                    $yaTenianRol++;
                    continue;
                }

                // ➕ Asignar rol 6 (Cliente)
                DB::connection('mysql')
                    ->table('model_has_roles')
                    ->insert([
                        'role_id' => 6,
                        'subrol_id' => null,
                        'firebird_identity_id' => $identity->id,
                        'model_type' => 'firebird_identity',
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ]);

                $this->info("🎭 Rol asignado a identity ID: {$identity->id} (CLIE: {$identity->firebird_clie_clave})");
                $rolesAsignados++;
            }

            $this->newLine();
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🎭 Roles asignados: {$rolesAsignados}");
            $this->info("✅ Ya tenían rol: {$yaTenianRol}");
            $this->info("📊 Total identidades: " . $identidadesClie->count());

        } catch (\Exception $e) {
            $this->error("❌ Error al asignar roles faltantes: " . $e->getMessage());
            Log::error('Error al asignar roles faltantes CLIE', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 🔗 Vincula usuarios que YA existen en USUARIOS pero NO tienen pivote
     */
    protected function vincularUsuariosExistentes(
        $clientes,
        $usuariosExistentes,
        $pivotesExistentes
    ): void {
        $vinculados = 0;
        $noEncontrados = 0;

        foreach ($clientes as $cliente) {
            // Si ya tiene pivote, skip
            if ($pivotesExistentes->has($cliente->CLAVE)) {
                continue;
            }

            $nombreCompleto = trim($cliente->NOMBRE ?? '');
            $correo = trim($cliente->EMAILPRED ?? '');

            // Validar correo
            if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // 🔍 Buscar usuario existente por CORREO o NOMBRE
            $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo, $nombreCompleto) {
                $correoMatch = strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo);
                $nombreMatch = strtoupper(trim($usuario->NOMBRE ?? '')) === strtoupper($nombreCompleto);

                return $correoMatch || $nombreMatch;
            });

            if ($usuarioExistente) {
                $this->info("🔗 Vinculando: {$nombreCompleto} (FB ID: {$usuarioExistente->ID} ↔ CLIE: {$cliente->CLAVE})");

                // Crear pivote
                $identityId = $this->registrarPivote(
                    $usuarioExistente->ID,
                    $cliente->CLAVE
                );

                if ($identityId) {
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
     * 🔌 Conexión a Firebird PRODUCCIÓN (srvasp01old)
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
     * 📋 Obtener clientes de CLIE03
     */
    protected function getClientesFromClie03()
    {
        return collect(
            $this->getProduccionConnection()->select("SELECT CLAVE, NOMBRE, EMAILPRED FROM CLIE03")
        );
    }

    /**
     * 📋 Obtener usuarios de tabla USUARIOS
     */
    protected function getUsuariosFromProduccion()
    {
        return collect(
            $this->getProduccionConnection()->select("SELECT ID, NOMBRE, CORREO FROM USUARIOS")
        );
    }

    /**
     * 🔐 Genera contraseña: primeras letras del nombre
     * Ejemplo: JUAN PANCHO LOPEZ = JPL
     */
    protected function generarPassword(string $nombreCompleto): string
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

        return $iniciales;
    }

    /**
     * ➕ Crea usuario en tabla USUARIOS de Firebird
     */
    protected function crearUsuarioFirebird(string $nombre, string $correo, string $passwordPlain): ?int
    {
        try {
            $connection = $this->getProduccionConnection();

            // 🔑 Obtener siguiente CLAVE
            $maxClave = $connection->selectOne("SELECT MAX(CLAVE) as MAX_CLAVE FROM USUARIOS");
            $nuevaClave = ($maxClave->MAX_CLAVE ?? 0) + 1;

            // 🔐 Hash de contraseña
            $passwordHash = Hash::make($passwordPlain);

            // 📝 Insertar en USUARIOS
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
                0,
                1,
                Carbon::now()->format('Y-m-d H:i:s'),
                0,
                0
            ]);

            // 🔍 Obtener el ID generado por el trigger
            $usuario = $connection->selectOne("SELECT ID FROM USUARIOS WHERE CLAVE = ?", [$nuevaClave]);

            return $usuario->ID ?? null;

        } catch (\Exception $e) {
            Log::error('Error al crear usuario Firebird desde CLIE', [
                'nombre' => $nombre,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 📌 Registra relación en tabla pivote MySQL para CLIE03
     */
    protected function registrarPivote(int $firebirdUserId, int $firebirdClieClave): ?int
    {
        try {
            // 🔍 Verificar si ya existe
            $existe = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_user_clave', $firebirdUserId)
                ->where('firebird_clie_tabla', 'CLIE03')
                ->where('firebird_clie_clave', $firebirdClieClave)
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
                    'firebird_tb_clave' => null,
                    'firebird_tb_tabla' => null,
                    'firebird_empresa' => null,
                    'firebird_clie_clave' => $firebirdClieClave,
                    'firebird_clie_tabla' => 'CLIE03',
                    'created_at' => Carbon::now()
                ]);

            $this->info("📌 Pivote creado con ID: {$id}");
            return $id;
        } catch (\Exception $e) {
            Log::error('Error al registrar pivote CLIE', [
                'fb_user_id' => $firebirdUserId,
                'clie_clave' => $firebirdClieClave,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 🎭 Asigna rol 6 en model_has_roles para clientes
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

            // ➕ Insertar rol 6 (Cliente)
            DB::connection('mysql')
                ->table('model_has_roles')
                ->insert([
                    'role_id' => 6,
                    'subrol_id' => null,
                    'firebird_identity_id' => $firebirdIdentityId,
                    'model_type' => 'firebird_identity',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);

            $this->info("🎭 Rol asignado: CLIENTE (role_id: 6)");
        } catch (\Exception $e) {
            Log::error('Error al asignar rol cliente', [
                'identity_id' => $firebirdIdentityId,
                'nombre' => $nombreCompleto,
                'error' => $e->getMessage()
            ]);
        }
    }
}