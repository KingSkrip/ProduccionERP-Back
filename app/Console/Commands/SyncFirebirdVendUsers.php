<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncFirebirdVendUsers extends Command
{
    protected $signature = 'firebird:sync-vend-users {--vincular-existentes : Vincular usuarios existentes sin pivote} {--asignar-roles : Asignar roles faltantes a identidades sin rol}';

    protected $description = 'Sincroniza VEND03 (vendedores) con USUARIOS Firebird y pivote MySQL';

    public function handle()
    {
        $this->info("🔥 Iniciando sincronización de vendedores VEND03");

        try {
            if ($this->option('asignar-roles')) {
                $this->newLine();
                $this->info("🎭 ASIGNANDO ROLES FALTANTES...");
                $this->asignarRolesFaltantes();
                $this->info("✅ Asignación de roles completada");
                return 0;
            }

            $this->info("📊 Obteniendo vendedores de VEND03...");
            $vendedores = $this->getVendedoresFromVend03();

            if ($vendedores->isEmpty()) {
                $this->error("❌ No se encontraron vendedores en VEND03");
                return 1;
            }

            $this->info("👥 Vendedores encontrados: " . $vendedores->count());

            $usuariosExistentes = $this->getUsuariosFromProduccion();

            $pivotesExistentes = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_vend_tabla', 'VEND03')
                ->whereNotNull('firebird_vend_clave')
                ->get()
                ->keyBy('firebird_vend_clave');

            if ($this->option('vincular-existentes')) {
                $this->newLine();
                $this->info("🔗 VINCULANDO USUARIOS EXISTENTES SIN PIVOTE...");
                $this->vincularUsuariosExistentes($vendedores, $usuariosExistentes, $pivotesExistentes);
                $this->info("✅ Vinculación completada");
                $this->newLine();
            }

            $procesados = 0;
            $nuevos     = 0;
            $omitidos   = 0;

            foreach ($vendedores as $vendedor) {
                $procesados++;

                $nombreCompleto = trim($vendedor->NOMBRE ?? '');
                $correo         = trim($vendedor->CORREOE ?? '');
                $claveVend      = $vendedor->CVE_VEND ?? 0;

                // ✅ Validar nombre
                if (empty($nombreCompleto) || strlen($nombreCompleto) < 3) {
                    $this->warn("⚠️ Vendedor CLAVE {$claveVend} sin nombre válido, omitido");
                    $omitidos++;
                    continue;
                }

                // ✅ Validar correo
                if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("⚠️ Vendedor CLAVE {$claveVend} ({$nombreCompleto}) sin correo válido, omitido");
                    $omitidos++;
                    continue;
                }

                // 1️⃣ Primero buscar si ya existe el usuario en Firebird
                $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo, $nombreCompleto) {
                    return strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo)
                        && strtoupper(trim($usuario->NOMBRE ?? '')) === strtoupper($nombreCompleto);
                });

                // 2️⃣ Luego checar si ya existe pivote para este CVE_VEND
                $pivoteExistente = DB::connection('mysql')
                    ->table('users_firebird_identities')
                    ->where('firebird_vend_clave', $claveVend)
                    ->where('firebird_vend_tabla', 'VEND03')
                    ->exists();

                if ($pivoteExistente) {
                    $this->info("⏭️  Ya existe pivote para VEND CLAVE: {$claveVend} ({$nombreCompleto})");
                    $omitidos++;
                    continue;
                }

                // 3️⃣ Si el usuario ya existe en Firebird, solo crear el pivote
                if ($usuarioExistente) {
                    $this->info("✅ Usuario ya existe en Firebird: {$nombreCompleto} (ID: {$usuarioExistente->ID})");

                    $identityId = $this->registrarPivote($usuarioExistente->ID, $claveVend);

                    if ($identityId) {
                        $this->asignarRol($identityId, $nombreCompleto);
                    }

                    $omitidos++;
                    continue;
                }

                // 4️⃣ No existe ni en Firebird ni pivote → crear usuario nuevo
                $this->info("➕ Creando usuario: {$nombreCompleto}");

                $passwordPlain = $this->generarPassword($nombreCompleto);
                $nuevoId = $this->crearUsuarioFirebird($nombreCompleto, $correo, $passwordPlain);

                if ($nuevoId) {
                    $this->info("✅ Usuario creado con ID: {$nuevoId}");
                    $this->info("🔐 Password: {$passwordPlain}");

                    $identityId = $this->registrarPivote($nuevoId, $claveVend);

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
            $this->info("🎯 RESUMEN DE SINCRONIZACIÓN - VEND03");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📋 Total procesados: {$procesados}");
            $this->info("✅ Nuevos usuarios:  {$nuevos}");
            $this->info("⏭️  Omitidos (ya existían): {$omitidos}");
            $this->info("🗄️  Tabla: VEND03");

            $this->newLine();
            $this->info("🎭 Verificando roles faltantes...");
            $this->asignarRolesFaltantes();

            return 0;
        } catch (\Exception $e) {
            $this->error("💥 Error fatal: " . $e->getMessage());
            Log::error('Error en sync VEND users', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 🎭 Asigna rol 7 (Vendedor) a identidades de VEND03 que no tienen rol
     */
    protected function asignarRolesFaltantes(): void
    {
        try {
            $identidadesVend = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_vend_tabla', 'VEND03')
                ->whereNotNull('firebird_vend_clave')
                ->get();

            $this->info("📊 Identidades de VEND03 encontradas: " . $identidadesVend->count());

            $rolesAsignados = 0;
            $yaTenianRol    = 0;

            foreach ($identidadesVend as $identity) {
                $tieneRol = DB::connection('mysql')
                    ->table('model_has_roles')
                    ->where('firebird_identity_id', $identity->id)
                    ->exists();

                if ($tieneRol) {
                    $yaTenianRol++;
                    continue;
                }

                DB::connection('mysql')
                    ->table('model_has_roles')
                    ->insert([
                        'role_id'              => 7,
                        'subrol_id'            => null,
                        'firebird_identity_id' => $identity->id,
                        'model_type'           => 'firebird_identity',
                        'created_at'           => Carbon::now(),
                        'updated_at'           => Carbon::now(),
                    ]);

                $this->info("🎭 Rol 7 asignado a identity ID: {$identity->id} (VEND: {$identity->firebird_vend_clave})");
                $rolesAsignados++;
            }

            $this->newLine();
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🎭 Roles asignados: {$rolesAsignados}");
            $this->info("✅ Ya tenían rol:   {$yaTenianRol}");
            $this->info("📊 Total identidades: " . $identidadesVend->count());
        } catch (\Exception $e) {
            $this->error("❌ Error al asignar roles faltantes: " . $e->getMessage());
            Log::error('Error al asignar roles faltantes VEND', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 🔗 Vincula usuarios existentes en USUARIOS que no tienen pivote VEND03
     * ⚠️  Solo hace match por CORREO — nunca por nombre para evitar falsos positivos
     * ⚠️  Si el usuario ya tiene un pivote VEND03 con diferente VEND CLAVE, NO crea otro — lo actualiza
     */
    protected function vincularUsuariosExistentes($vendedores, $usuariosExistentes, $pivotesExistentes): void
    {
        $vinculados    = 0;
        $noEncontrados = 0;

        foreach ($vendedores as $vendedor) {

            $nombreCompleto = trim($vendedor->NOMBRE ?? '');
            $correo = trim($vendedor->CORREOE ?? '');

            if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $usuarioExistente = $usuariosExistentes->first(function ($u) use ($correo, $nombreCompleto) {
                return strtolower(trim($u->CORREO ?? '')) === strtolower($correo)
                    && strtoupper(trim($u->NOMBRE ?? '')) === strtoupper($nombreCompleto);
            });

            if (!$usuarioExistente) {
                continue;
            }

            // 🔒 SOLO validar por CLAVE VEND
            $pivoteExistente = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_vend_clave', $vendedor->CVE_VEND)
                ->where('firebird_vend_tabla', 'VEND03')
                ->exists();

            if ($pivoteExistente) {
                continue;
            }

            // ✅ Crear pivote limpio
            $identityId = $this->registrarPivote(
                $usuarioExistente->ID,
                $vendedor->CVE_VEND
            );

            if ($identityId) {
                $this->asignarRol($identityId, $nombreCompleto);
            }
        }


        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("🔗 Usuarios vinculados/actualizados: {$vinculados}");
        $this->info("⚠️  No encontrados en USUARIOS: {$noEncontrados}");
    }

    /**
     * 🔌 Conexión a Firebird PRODUCCIÓN
     */
    protected function getProduccionConnection()
    {
        config([
            'database.connections.firebird_produccion' => [
                'driver'            => 'firebird',
                'host'              => env('FB_HOST'),
                'port'              => env('FB_PORT'),
                'database'          => env('FB_DATABASE'),
                'username'          => env('FB_USERNAME'),
                'password'          => env('FB_PASSWORD'),
                'charset'           => env('FB_CHARSET', 'UTF8'),
                'dialect'           => 3,
                'quote_identifiers' => false,
            ]
        ]);

        DB::purge('firebird_produccion');
        return DB::connection('firebird_produccion');
    }

    /**
     * 📋 Obtener vendedores de VEND03
     */
    protected function getVendedoresFromVend03()
    {
        return collect(
            $this->getProduccionConnection()->select("SELECT CVE_VEND, NOMBRE, CORREOE FROM VEND03")
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
     * 🔐 Genera contraseña: iniciales de palabras significativas del nombre
     */
    protected function generarPassword(string $nombreCompleto): string
    {
        $palabras = preg_split('/\s+/', strtoupper(trim($nombreCompleto)));

        $palabras = array_filter($palabras, fn($p) => strlen($p) >= 3);

        $iniciales = '';
        foreach ($palabras as $palabra) {
            $iniciales .= substr($palabra, 0, 1);
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

            $maxClave  = $connection->selectOne("SELECT MAX(CLAVE) as MAX_CLAVE FROM USUARIOS");
            $nuevaClave = ($maxClave->MAX_CLAVE ?? 0) + 1;

            $passwordHash = Hash::make($passwordPlain);

            $connection->insert("
                INSERT INTO USUARIOS (
                    CLAVE, NOMBRE, CORREO, PASSWORD2,
                    PHOTO, DEPTO, STATUS, FECHAACT, SESIONES, PERFIL
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
                0,
            ]);

            $usuario = $connection->selectOne("SELECT ID FROM USUARIOS WHERE CLAVE = ?", [$nuevaClave]);

            return $usuario->ID ?? null;
        } catch (\Exception $e) {
            Log::error('Error al crear usuario Firebird desde VEND', [
                'nombre' => $nombre,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 📌 Registra relación en tabla pivote MySQL para VEND03
     */
    protected function registrarPivote(int $firebirdUserId, int $firebirdVendClave): ?int
    {
        try {
            // 🔎 ¿Ya existe pivote exacto (user + vend)?
            $exacto = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_user_clave', $firebirdUserId)
                ->where('firebird_vend_tabla', 'VEND03')
                ->where('firebird_vend_clave', $firebirdVendClave)
                ->first();

            if ($exacto) {
                $this->info("⏭️  Pivote ya existe, omitiendo ID: {$exacto->id}");
                return $exacto->id;
            }

            // 🔎 ¿Ya existe cualquier registro para este usuario?
            $registroExistente = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_user_clave', $firebirdUserId)
                ->first();

            if ($registroExistente) {
                // ❌ Ya existe un registro para este usuario → NO tocar nada
                $this->warn("⚠️  Ya existe registro para user ID {$firebirdUserId}, omitiendo sin modificar");
                return null;
            }

            // ✅ No existe nada → insertar nuevo
            $id = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->insertGetId([
                    'firebird_user_clave' => $firebirdUserId,
                    'firebird_tb_clave'   => null,
                    'firebird_tb_tabla'   => null,
                    'firebird_empresa'    => null,
                    'firebird_clie_clave' => null,
                    'firebird_clie_tabla' => null,
                    'firebird_vend_clave' => $firebirdVendClave,
                    'firebird_vend_tabla' => 'VEND03',
                    'created_at'          => now(),
                ]);

            $this->info("📌 Pivote creado con ID: {$id}");
            return $id;
        } catch (\Exception $e) {
            $this->error("❌ Error al registrar pivote: " . $e->getMessage());
            Log::error('Error al registrar pivote VEND', [
                'fb_user_id' => $firebirdUserId,
                'vend_clave' => $firebirdVendClave,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 🎭 Asigna rol 7 (Vendedor) en model_has_roles
     */
    protected function asignarRol(int $firebirdIdentityId, string $nombreCompleto): void
    {
        try {
            $yaTieneRol = DB::connection('mysql')
                ->table('model_has_roles')
                ->where('firebird_identity_id', $firebirdIdentityId)
                ->exists();

            if ($yaTieneRol) {
                $this->info("🎭 Usuario ya tiene rol asignado");
                return;
            }

            DB::connection('mysql')
                ->table('model_has_roles')
                ->insert([
                    'role_id'              => 7,
                    'subrol_id'            => null,
                    'firebird_identity_id' => $firebirdIdentityId,
                    'model_type'           => 'firebird_identity',
                    'created_at'           => Carbon::now(),
                    'updated_at'           => Carbon::now(),
                ]);

            $this->info("🎭 Rol asignado: VENDEDOR (role_id: 7)");
        } catch (\Exception $e) {
            Log::error('Error al asignar rol vendedor', [
                'identity_id' => $firebirdIdentityId,
                'nombre'      => $nombreCompleto,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}