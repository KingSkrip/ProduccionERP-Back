<?php

namespace App\Console\Commands;

use App\Services\FirebirdConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class SyncUsuariosAccess extends Command
{
    protected $signature = 'firebird:sync-usuarios-sin-empresa';
    protected $description = 'Crea pivotes para usuarios de USUARIOS que NO están en la tabla pivote (usuarios de acceso sin empresa)';
    protected FirebirdConnectionService $firebirdService;

    public function __construct(FirebirdConnectionService $firebirdService)
    {
        parent::__construct();
        $this->firebirdService = $firebirdService;
    }

    public function handle()
    {
        $this->info("🔥 Sincronizando usuarios sin empresa (solo acceso)...");

        try {
            // 📋 Obtener todos los usuarios de Firebird USUARIOS
            $this->info("📊 Obteniendo usuarios de Firebird USUARIOS...");
            $usuariosFirebird = $this->getUsuariosFromProduccion();
            $this->info("👥 Total usuarios en USUARIOS: " . $usuariosFirebird->count());

            // 📌 Obtener IDs que YA están en la pivote (no CLAVEs)
            $idsEnPivote = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->pluck('firebird_user_clave')
                ->toArray();

            $this->info("📌 Usuarios ya en pivote: " . count($idsEnPivote));

            $creados = 0;
            $omitidos = 0;

            foreach ($usuariosFirebird as $usuario) {
                $id = $usuario->ID;  // ✅ Usar ID en lugar de CLAVE
                $nombre = trim($usuario->NOMBRE ?? 'Sin nombre');

                // 🔍 Si ya está en la pivote, skip
                if (in_array($id, $idsEnPivote)) {
                    $omitidos++;
                    continue;
                }

                // ➕ Crear pivote SOLO con firebird_user_clave (que ahora es el ID)
                $identityId = $this->crearPivoteSinEmpresa($id);

                if ($identityId) {
                    $this->info("✅ Usuario: {$nombre} (ID: {$id}) → Pivote ID: {$identityId}");

                    // 🎭 Asignar rol
                    $this->asignarRol($identityId, $nombre);

                    $creados++;
                }
            }

            // 📊 Resumen
            $this->newLine();
            $this->info("🎯 RESUMEN DE SINCRONIZACIÓN");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("👥 Total usuarios en USUARIOS: " . $usuariosFirebird->count());
            $this->info("✅ Pivotes creados (sin empresa): {$creados}");
            $this->info("⏭️  Omitidos (ya en pivote): {$omitidos}");

            return 0;
        } catch (\Exception $e) {
            $this->error("💥 Error fatal: " . $e->getMessage());
            Log::error('Error en sync usuarios sin empresa', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }


    /**
     * 📋 Obtener usuarios de tabla USUARIOS (ahora con ID)
     */
    protected function getUsuariosFromProduccion()
    {
        return collect(
            $this->firebirdService->getProductionConnection()->select("SELECT ID, NOMBRE FROM USUARIOS")
        );
    }

    /**
     * ➕ Crea pivote SOLO con firebird_user_clave (que es el ID del usuario)
     * Los demás campos van NULL
     */
    protected function crearPivoteSinEmpresa(int $firebirdUserId): ?int  // ✅ Cambiado nombre de parámetro
    {
        try {

            // ➕ Insertar pivote minimalista (solo user_clave que es el ID)
            $id = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->insertGetId([
                    'firebird_user_clave' => $firebirdUserId,  // ✅ Ahora es el ID
                    'firebird_tb_clave' => null,             // Sin TB
                    'firebird_tb_tabla' => 'USUARIOS', // Sin tabla
                    'firebird_empresa' => null,
                    'firebird_clie_clave' => null,
                    'firebird_clie_tabla' => null,
                    'firebird_vend_clave' => null,
                    'firebird_vend_clave' => null,
                    'created_at' => Carbon::now()
                ]);

            return $id;
        } catch (Exception $e) {
            Log::error('Error al crear pivote sin empresa', [
                'fb_user_id' => $firebirdUserId,  // ✅ Cambiado nombre en log
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
            $this->info("  🎭 Rol: {$roleName}");
        } catch (\Exception $e) {
            Log::error('Error al asignar rol', [
                'identity_id' => $firebirdIdentityId,
                'nombre' => $nombreCompleto,
                'error' => $e->getMessage()
            ]);
        }
    }
}