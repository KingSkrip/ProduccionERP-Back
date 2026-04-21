<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncFirebirdProvUsers extends Command
{
    protected $signature = 'firebird:sync-prov-users 
        {--vincular-existentes : Vincular usuarios existentes sin pivote}
        {--asignar-roles : Asignar roles faltantes a identidades sin rol}
        {--export-passwords= : Exportar contraseñas generadas a archivo CSV (ej: /tmp/passwords.csv)}';

    protected $description = 'Sincroniza PROV03 (proveedores) con USUARIOS Firebird y pivote MySQL';

    /** @var array Contraseñas generadas en esta ejecución para exportar */
    protected array $passwordsGeneradas = [];

    public function handle()
    {
        $this->info("🔥 Iniciando sincronización de proveedores PROV03");

        try {
            // 🎭 Si se pide solo asignar roles, ejecutar esa función y salir
            if ($this->option('asignar-roles')) {
                $this->newLine();
                $this->info("🎭 ASIGNANDO ROLES FALTANTES...");
                $this->asignarRolesFaltantes();
                $this->info("✅ Asignación de roles completada");
                return 0;
            }

            // 📊 Obtener proveedores de PROV03
            $this->info("📊 Obteniendo proveedores de PROV03...");
            $proveedores = $this->getProveedoresFromProv03();

            if ($proveedores->isEmpty()) {
                $this->error("❌ No se encontraron proveedores en PROV03");
                return 1;
            }

            $this->info("🏭 Proveedores encontrados: " . $proveedores->count());

            // 📝 Obtener todos los usuarios existentes en Firebird USUARIOS
            $usuariosExistentes = $this->getUsuariosFromProduccion();

            // 📌 Obtener pivotes existentes para PROV03
            $pivotesExistentes = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_prov_tabla', 'PROV03')
                ->whereNotNull('firebird_prov_clave')
                ->get()
                ->keyBy('firebird_prov_clave');

            // 🔗 Si se activa la opción, vincular usuarios existentes primero
            if ($this->option('vincular-existentes')) {
                $this->newLine();
                $this->info("🔗 VINCULANDO USUARIOS EXISTENTES SIN PIVOTE...");
                $this->vincularUsuariosExistentes(
                    $proveedores,
                    $usuariosExistentes,
                    $pivotesExistentes
                );
                $this->info("✅ Vinculación completada");
                $this->newLine();
            }

            $procesados = 0;
            $nuevos     = 0;
            $omitidos   = 0;

            foreach ($proveedores as $proveedor) {
                $procesados++;

                $nombreCompleto = trim($proveedor->NOMBRE ?? '');
                $correo         = trim($proveedor->EMAILPRED ?? $proveedor->EMAIL ?? '');
                $claveProv      = $proveedor->CLAVE ?? 0;

                // ✅ Validar nombre
                if (empty($nombreCompleto) || strlen($nombreCompleto) < 3) {
                    $this->warn("⚠️ Proveedor CLAVE {$claveProv} sin nombre válido, omitido");
                    $omitidos++;
                    continue;
                }

                // ✅ Validar correo
                if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("⚠️ Proveedor CLAVE {$claveProv} ({$nombreCompleto}) sin correo válido, omitido");
                    $omitidos++;
                    continue;
                }

                // 🔎 VERIFICAR SI YA EXISTE PIVOTE PARA ESTE PROVEEDOR
                if ($pivotesExistentes->has($claveProv)) {
                    $this->info("⏭️  Ya existe pivote para: {$nombreCompleto} (PROV CLAVE: {$claveProv})");
                    $omitidos++;
                    continue;
                }

                // 🔎 Buscar si ya existe usuario en Firebird con mismo CORREO
                $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo) {
                    return strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo);
                });

                if ($usuarioExistente) {
                    $this->info("✅ Usuario ya existe en Firebird: {$nombreCompleto} (ID: {$usuarioExistente->ID})");

                    $identityId = $this->registrarPivote($usuarioExistente->ID, $claveProv);

                    if ($identityId) {
                        $this->asignarRol($identityId, $nombreCompleto);
                    }

                    $omitidos++;
                    continue;
                }

                // 🆕 Crear nuevo usuario en Firebird USUARIOS
                $this->info("➕ Creando usuario: {$nombreCompleto}");

                $passwordPlain = $this->generarPasswordSeguro();
                $nuevoId       = $this->crearUsuarioFirebird($nombreCompleto, $correo, $passwordPlain);

                if ($nuevoId) {
                    $this->info("✅ Usuario creado con ID: {$nuevoId}");
                    $this->info("🔐 Password generado para: {$nombreCompleto}");

                    // Guardar para exportar
                    $this->passwordsGeneradas[] = [
                        'prov_clave'     => $claveProv,
                        'nombre'         => $nombreCompleto,
                        'correo'         => $correo,
                        'password_plain' => $passwordPlain,
                        'firebird_id'    => $nuevoId,
                        'fecha'          => Carbon::now()->format('Y-m-d H:i:s'),
                    ];

                    $identityId = $this->registrarPivote($nuevoId, $claveProv);

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
            $this->info("🎯 RESUMEN DE SINCRONIZACIÓN - PROV03");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📋 Total procesados: {$procesados}");
            $this->info("✅ Nuevos usuarios:   {$nuevos}");
            $this->info("⏭️  Omitidos:          {$omitidos}");
            $this->info("🗄️  Tabla:             PROV03");

            // 🎭 Asignar roles faltantes al final
            $this->newLine();
            $this->info("🎭 Verificando roles faltantes...");
            $this->asignarRolesFaltantes();

            // 📄 Exportar CSV si se solicitó
            if ($rutaCsv = $this->option('export-passwords')) {
                $this->exportarPasswordsCsv($rutaCsv);
            } elseif (!empty($this->passwordsGeneradas)) {
                $rutaDefault = storage_path('app/prov_passwords_' . Carbon::now()->format('Ymd_His') . '.csv');
                $this->exportarPasswordsCsv($rutaDefault);
                $this->info("📄 Contraseñas exportadas a: {$rutaDefault}");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("💥 Error fatal: " . $e->getMessage());
            Log::error('Error en sync PROV users', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    // =========================================================
    // 🔐 GENERA CONTRASEÑA SEGURA DE 16 CARACTERES
    // =========================================================
    protected function generarPasswordSeguro(): string
    {
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // sin I, O (confusos)
        $lower   = 'abcdefghjkmnpqrstuvwxyz';     // sin i, l, o
        $digits  = '23456789';                     // sin 0, 1 (confusos)
        $special = '@#$%&!?*+';

        // Garantizar al menos 1 de cada tipo
        $password  = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // Rellenar los 12 caracteres restantes con todo el pool
        $pool = $upper . $lower . $digits . $special;
        for ($i = 4; $i < 16; $i++) {
            $password .= $pool[random_int(0, strlen($pool) - 1)];
        }

        // Mezclar para que los caracteres garantizados no siempre estén al inicio
        return str_shuffle($password);
    }

    // =========================================================
    // 📄 EXPORTAR CSV DE CONTRASEÑAS
    // =========================================================
    protected function exportarPasswordsCsv(string $ruta): void
    {
        if (empty($this->passwordsGeneradas)) {
            $this->warn("⚠️ No hay contraseñas nuevas para exportar");
            return;
        }

        $fp = fopen($ruta, 'w');
        if (!$fp) {
            $this->error("❌ No se pudo crear el archivo: {$ruta}");
            return;
        }

        // Encabezado con BOM UTF-8 para que Excel lo abra bien
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['PROV_CLAVE', 'NOMBRE', 'CORREO', 'PASSWORD', 'FIREBIRD_ID', 'FECHA_CREACION']);

        foreach ($this->passwordsGeneradas as $row) {
            fputcsv($fp, [
                $row['prov_clave'],
                $row['nombre'],
                $row['correo'],
                $row['password_plain'],
                $row['firebird_id'],
                $row['fecha'],
            ]);
        }

        fclose($fp);
        $this->info("📄 CSV generado: {$ruta} (" . count($this->passwordsGeneradas) . " registros)");
    }

    // =========================================================
    // 🎭 ASIGNAR ROLES FALTANTES
    // =========================================================
    protected function asignarRolesFaltantes(): void
    {
        try {
            $identidadesProv = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_prov_tabla', 'PROV03')
                ->whereNotNull('firebird_prov_clave')
                ->get();

            $this->info("📊 Identidades PROV03 encontradas: " . $identidadesProv->count());

            $rolesAsignados = 0;
            $yaTenianRol    = 0;

            foreach ($identidadesProv as $identity) {
                $tieneRol = DB::connection('mysql')
                    ->table('model_has_roles')
                    ->where('firebird_identity_id', $identity->id)
                    ->exists();

                if ($tieneRol) {
                    $yaTenianRol++;
                    continue;
                }

                // rol 7 = Proveedor (ajusta al role_id que uses en tu app)
                DB::connection('mysql')
                    ->table('model_has_roles')
                    ->insert([
                        'role_id'             => 7,
                        'subrol_id'           => null,
                        'firebird_identity_id' => $identity->id,
                        'model_type'          => 'firebird_identity',
                        'created_at'          => Carbon::now(),
                        'updated_at'          => Carbon::now(),
                    ]);

                $this->info("🎭 Rol asignado a identity ID: {$identity->id} (PROV: {$identity->firebird_prov_clave})");
                $rolesAsignados++;
            }

            $this->newLine();
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🎭 Roles asignados:  {$rolesAsignados}");
            $this->info("✅ Ya tenían rol:    {$yaTenianRol}");
            $this->info("📊 Total:            " . $identidadesProv->count());
        } catch (\Exception $e) {
            $this->error("❌ Error al asignar roles faltantes: " . $e->getMessage());
            Log::error('Error al asignar roles faltantes PROV', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================
    // 🔗 VINCULAR USUARIOS EXISTENTES SIN PIVOTE
    // =========================================================
    protected function vincularUsuariosExistentes($proveedores, $usuariosExistentes, $pivotesExistentes): void
    {
        $vinculados    = 0;
        $noEncontrados = 0;

        foreach ($proveedores as $proveedor) {
            if ($pivotesExistentes->has($proveedor->CLAVE)) {
                continue;
            }

            $nombreCompleto = trim($proveedor->NOMBRE ?? '');
            $correo         = trim($proveedor->EMAILPRED ?? $proveedor->EMAIL ?? '');

            if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $usuarioExistente = $usuariosExistentes->first(function ($usuario) use ($correo, $nombreCompleto) {
                $correoMatch = strtolower(trim($usuario->CORREO ?? '')) === strtolower($correo);
                $nombreMatch = strtoupper(trim($usuario->NOMBRE ?? '')) === strtoupper($nombreCompleto);
                return $correoMatch || $nombreMatch;
            });

            if ($usuarioExistente) {
                $this->info("🔗 Vinculando: {$nombreCompleto} (FB ID: {$usuarioExistente->ID} ↔ PROV: {$proveedor->CLAVE})");

                $identityId = $this->registrarPivote($usuarioExistente->ID, $proveedor->CLAVE);

                if ($identityId) {
                    $this->asignarRol($identityId, $nombreCompleto);
                    $vinculados++;
                }
            } else {
                $noEncontrados++;
            }
        }

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("🔗 Usuarios vinculados:              {$vinculados}");
        $this->info("⚠️  No encontrados en USUARIOS:      {$noEncontrados}");
    }

    // =========================================================
    // 🔌 CONEXIÓN FIREBIRD PRODUCCIÓN
    // =========================================================
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
            ],
        ]);

        DB::purge('firebird_produccion');
        return DB::connection('firebird_produccion');
    }

    protected function getProveedoresFromProv03()
    {
        return collect(
            $this->getProduccionConnection()->select("SELECT CLAVE, NOMBRE, EMAILPRED FROM PROV03")
        );
    }

    protected function getUsuariosFromProduccion()
    {
        return collect(
            $this->getProduccionConnection()->select("SELECT ID, NOMBRE, CORREO FROM USUARIOS")
        );
    }

    // =========================================================
    // ➕ CREAR USUARIO EN FIREBIRD
    // =========================================================
    protected function crearUsuarioFirebird(string $nombre, string $correo, string $passwordPlain): ?int
    {
        try {
            $connection = $this->getProduccionConnection();

            $maxClave   = $connection->selectOne("SELECT MAX(CLAVE) as MAX_CLAVE FROM USUARIOS");
            $nuevaClave = ($maxClave->MAX_CLAVE ?? 0) + 1;

            $passwordHash = Hash::make($passwordPlain);

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
                0,
            ]);

            $usuario = $connection->selectOne("SELECT ID FROM USUARIOS WHERE CLAVE = ?", [$nuevaClave]);

            return $usuario->ID ?? null;
        } catch (\Exception $e) {
            Log::error('Error al crear usuario Firebird desde PROV', [
                'nombre' => $nombre,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================
    // 📌 REGISTRAR PIVOTE MySQL
    // =========================================================
    protected function registrarPivote(int $firebirdUserId, int $firebirdProvClave): ?int
    {
        try {
            // ¿Ya existe pivote exacto?
            $exacto = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_user_clave',  $firebirdUserId)
                ->where('firebird_prov_tabla',  'PROV03')
                ->where('firebird_prov_clave',  $firebirdProvClave)
                ->first();

            if ($exacto) {
                $this->info("⏭️  Pivote ya existe, omitiendo ID: {$exacto->id}");
                return $exacto->id;
            }

            // ¿Ya existe cualquier registro para este usuario?
            $registroExistente = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->where('firebird_user_clave', $firebirdUserId)
                ->first();

            if ($registroExistente) {
                $this->warn("⚠️  Ya existe registro para user ID {$firebirdUserId}, omitiendo sin modificar");
                return null;
            }

            // ✅ Insertar nuevo pivote
            $id = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->insertGetId([
                    'firebird_user_clave' => $firebirdUserId,
                    'firebird_tb_clave'   => null,
                    'firebird_tb_tabla'   => null,
                    'firebird_empresa'    => null,
                    'firebird_clie_clave' => null,
                    'firebird_clie_tabla' => null,
                    'firebird_vend_clave' => null,
                    'firebird_vend_tabla' => null,
                    'firebird_prov_clave' => $firebirdProvClave,
                    'firebird_prov_tabla' => 'PROV03',
                    'created_at'          => now(),
                ]);

            $this->info("📌 Pivote creado con ID: {$id}");
            return $id;
        } catch (\Exception $e) {
            $this->error("❌ Error al registrar pivote: " . $e->getMessage());
            Log::error('Error al registrar pivote PROV', [
                'fb_user_id'  => $firebirdUserId,
                'prov_clave'  => $firebirdProvClave,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================
    // 🎭 ASIGNAR ROL A IDENTIDAD
    // =========================================================
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

            // rol 7 = Proveedor (ajusta si es diferente en tu app)
            DB::connection('mysql')
                ->table('model_has_roles')
                ->insert([
                    'role_id'             => 8,
                    'subrol_id'           => null,
                    'firebird_identity_id' => $firebirdIdentityId,
                    'model_type'          => 'firebird_identity',
                    'created_at'          => Carbon::now(),
                    'updated_at'          => Carbon::now(),
                ]);

            $this->info("🎭 Rol asignado: PROVEEDOR (role_id: 8)");
        } catch (\Exception $e) {
            Log::error('Error al asignar rol proveedor', [
                'identity_id' => $firebirdIdentityId,
                'nombre'      => $nombreCompleto,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}