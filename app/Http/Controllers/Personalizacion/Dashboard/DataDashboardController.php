<?php

namespace App\Http\Controllers\Personalizacion\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorAccessQrCodeResource;
use App\Http\Resources\UsuarioResource;

use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use App\Models\UserPuesto;
use App\Services\Checador\ChecadorQrService;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaManualService;


use Exception;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class DataDashboardController extends Controller
{
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';
    protected FirebirdConnectionService $firebirdService;
    protected ChecadorQrService $qrService; // 👈 3. DECLARAR PROPIEDAD

    public function __construct(
        FirebirdConnectionService $firebirdService,
        ChecadorQrService $qrService // 👈 4. INYECTAR EN CONSTRUCTOR
    ) {
        $this->jwtSecret = config('jwt.secret');
        $this->firebirdService = $firebirdService;
        $this->qrService = $qrService;
    }

    public function me(Request $request)
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json(['message' => 'Token requerido'], 401);
            }

            $decoded = JWT::decode(
                $token,
                new Key($this->jwtSecret, 'HS256')
            );

            if (!isset($decoded->sub) || !ctype_digit((string)$decoded->sub)) {
                return response()->json(['message' => 'Token inválido (sub inválido)'], 401);
            }

            if (!isset($decoded->exp) || $decoded->exp < time()) {
                return response()->json(['message' => 'Token expirado'], 401);
            }

            if (!isset($decoded->iat) || $decoded->iat > time()) {
                return response()->json(['message' => 'Token no válido aún'], 401);
            }

            $sub = (int) $decoded->sub;

            Log::info('🧾 ME_JWT_SUB', [
                'sub' => $sub,
                'type' => gettype($sub),
            ]);

            if (!$sub) {
                return response()->json(['message' => 'Token inválido (sin sub)'], 401);
            }

            $usuario = Users::find($sub);

            Log::info('👤 ME_FIREBIRD_USER_BY_ID', [
                'found' => (bool) $usuario,
                'sub' => $sub,
                'firebird_id' => $usuario->ID ?? null,
                'firebird_clave' => $usuario->CLAVE ?? null,
                'correo' => $usuario->CORREO ?? null,
                'nombre' => $usuario->NOMBRE ?? null,
            ]);

            if (!$usuario) {
                $usuarioPorClave = Users::where('CLAVE', $sub)->first();

                Log::warning('🧯 ME_FALLBACK_BY_CLAVE', [
                    'sub' => $sub,
                    'found_by_clave' => (bool) $usuarioPorClave,
                    'usuario_id' => $usuarioPorClave->ID ?? null,
                    'usuario_clave' => $usuarioPorClave->CLAVE ?? null,
                ]);

                $usuario = $usuarioPorClave;
            }

            if (!$usuario) {
                return response()->json(['message' => 'Usuario no encontrado en Firebird'], 404);
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', (int)$usuario->ID)->first();

            Log::info('📌 ME_MYSQL_IDENTITY_LOOKUP', [
                'found' => (bool) $identity,
                'lookup_by' => 'firebird_user_clave = USUARIOS.ID',
                'firebird_user_id' => $usuario->ID,
                'identity_id' => $identity->id ?? null,
                'identity_firebird_user_clave' => $identity->firebird_user_clave ?? null,
                'identity_firebird_tb_clave' => $identity->firebird_tb_clave ?? null,
                'identity_firebird_clie_clave' => $identity->firebird_clie_clave ?? null,
                'identity_tb_tabla' => $identity->firebird_tb_tabla ?? null,
                'identity_clie_tabla' => $identity->firebird_clie_tabla ?? null,
                'firebird_vend_clave' => null,
                'identity_empresa' => $identity->firebird_empresa ?? null,
            ]);

            if (!$identity) {
                $identityLegacy = UserFirebirdIdentity::where('firebird_user_clave', (int)$usuario->CLAVE)->first();

                Log::warning('🧯 ME_IDENTITY_LEGACY_FALLBACK', [
                    'found' => (bool) $identityLegacy,
                    'lookup_by' => 'firebird_user_clave = USUARIOS.CLAVE (legacy)',
                    'usuarios_clave' => $usuario->CLAVE,
                    'identity_id' => $identityLegacy->id ?? null,
                    'identity_tb_clave' => $identityLegacy->firebird_tb_clave ?? null,
                    'identity_clie_clave' => $identityLegacy->firebird_clie_clave ?? null,
                    'identity_empresa' => $identityLegacy->firebird_empresa ?? null,
                    'identity_tb_tabla' => $identityLegacy->firebird_tb_tabla ?? null,
                    'identity_clie_tabla' => $identityLegacy->firebird_clie_tabla ?? null,
                    'firebird_vend_clave' => null,
                ]);

                $identity = $identityLegacy;
            }

            if (!$identity) {
                return response()->json(['message' => 'Identidad de usuario no configurada'], 404);
            }

            $esEmpleado = $identity->firebird_tb_clave !== null;
            $esCliente = $identity->firebird_clie_clave !== null;
            $esVendedor = $identity->firebird_vend_clave !== null;
            $esProveedor = $identity->firebird_prov_clave !== null;

            Log::info('🔍 ME_USER_TYPE_DETECTION', [
                'es_empleado' => $esEmpleado,
                'es_cliente'  => $esCliente,
                'es_vendedor' => $esVendedor,
            ]);

            $roles = $identity->roles()->get();
            $turnoActivo = null;

            if ($esEmpleado) {
                $turnoActivo = $identity->turnoActivo()
                    ->with(['turno.turnoDias', 'status'])
                    ->first();
            }

            // 🎫 QR fijo de checador (mismo token siempre, mientras esté activo)
            $qrData = null;
            $qr = $this->qrService->generar($identity->id); // genera si no existe, reutiliza si ya existe
            $qrData = (new ChecadorAccessQrCodeResource($qr))->resolve();

            Log::info('🎫 ME_QR_CHECADOR', [
                'identity_id' => $identity->id,
                'qr_token' => $qr->token,
                'qr_creado' => $qr->wasRecentlyCreated,
            ]);

            Log::info('🎭 ME_ROLES_TURNO', [
                'roles_count' => $roles->count(),
                'turno_activo' => (bool) $turnoActivo,
            ]);

            $departamentos = collect();
            $slRow = null;
            $vcRow = null;
            $hvcRow = null;
            $mfRow = null;
            $acRows = collect();
            $tbRow = null;
            $deptoRow = null;
            $puestoRow = null;
            $clieRow = null;

            if ($esEmpleado) {
                $tbClave = $identity->firebird_tb_clave;
                $tbClaveNorm = is_string($tbClave) ? trim($tbClave) : $tbClave;
                $empresaNoi = $identity->firebird_empresa ?? '04';

                Log::info('🧠 ME_EMPLEADO_KEYS_RESOLVED', [
                    'auth_uses' => 'USUARIOS.ID (JWT sub)',
                    'noi_uses' => 'TB.CLAVE (identity.firebird_tb_clave)',
                    'firebird_id' => $usuario->ID,
                    'usuarios_clave' => $usuario->CLAVE,
                    'tb_clave' => $tbClave,
                    'tb_clave_norm' => $tbClaveNorm,
                    'empresaNoi' => $empresaNoi,
                ]);

                try {
                    $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                    $departamentos = $firebirdNoi->getMasterTable('DEPTOS')->keyBy('CLAVE');

                    $sl = $firebirdNoi->getOperationalTable('SL')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE_TRAB));
                    $slRow = $sl[$tbClaveNorm] ?? null;

                    $vc = $firebirdNoi->getOperationalTable('VC')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE_TRAB));
                    $vcRow = $vc[$tbClaveNorm] ?? null;

                    $hvc = $firebirdNoi->getMasterTable('HISTVAC')
                        ->keyBy(fn($row) => trim((string)$row->CVETRAB));
                    $hvcRow = $hvc[$tbClaveNorm] ?? null;

                    $mf = $firebirdNoi->getOperationalTable('MF')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE_TRAB));
                    $mfRow = $mf[$tbClaveNorm] ?? null;

                    $acRows = $firebirdNoi->getOperationalTable('AC')
                        ->filter(fn($row) => trim((string)$row->CLAVE_TRAB) === (string)$tbClaveNorm)
                        ->values();

                    $tb = $firebirdNoi->getOperationalTable('TB')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE));
                    $tbRow = $tb[$tbClaveNorm] ?? null;
                    if ($tbRow) {
                        // 🏢 DEPTO
                        $deptoClave = isset($tbRow->DEPTO) ? trim((string)$tbRow->DEPTO) : null;

                        if ($deptoClave) {
                            try {
                                $deptos = $firebirdNoi->getMasterTable('DEPTOS')
                                    ->keyBy(fn($row) => trim((string)$row->CLAVE));
                                $deptoRow = $deptos[$deptoClave] ?? null;

                                Log::info('🏢 ME_DEPTO_LOOKUP', [
                                    'depto_clave'  => $deptoClave,
                                    'depto_found'  => (bool) $deptoRow,
                                    'depto_nombre' => $deptoRow->NOMBRE ?? null,
                                ]);
                            } catch (\Throwable $e) {
                                Log::error('⚠️ ME_DEPTO_LOOKUP_ERROR', [
                                    'depto_clave' => $deptoClave,
                                    'error'       => $e->getMessage(),
                                ]);
                            }
                        }

                        // 👔 PUESTO
                        $puestoClave = isset($tbRow->PUESTO) ? trim((string)$tbRow->PUESTO) : null;

                        if ($puestoClave) {
                            try {
                                $puestos = $firebirdNoi->getMasterTable('PUESTOS')
                                    ->keyBy(fn($row) => trim((string)$row->CLAVE));
                                $puestoRow = $puestos[$puestoClave] ?? null;

                                Log::info('👔 ME_PUESTO_LOOKUP', [
                                    'puesto_clave'  => $puestoClave,
                                    'puesto_found'  => (bool) $puestoRow,
                                    'puesto_nombre' => $puestoRow->NOMBRE ?? null,
                                ]);
                            } catch (\Throwable $e) {
                                Log::error('⚠️ ME_PUESTO_LOOKUP_ERROR', [
                                    'puesto_clave' => $puestoClave,
                                    'error'        => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                    Log::info('✅ ME_EMPLEADO_NOI_DATA', [
                        'tb_clave' => $tbClaveNorm,
                        'found' => [
                            'tb' => (bool) $tbRow,
                            'sl' => (bool) $slRow,
                            'vc' => (bool) $vcRow,
                            'hvc' => (bool) $hvcRow,
                            'mf' => (bool) $mfRow,
                            'ac_count' => $acRows->count(),
                        ],
                        'depto_count' => $departamentos->count(),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('⚠️ ME_EMPLEADO_NOI_ERROR', [
                        'empresaNoi' => $empresaNoi,
                        'tbClave' => $tbClaveNorm,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($esCliente) {
                $clieClave = $identity->firebird_clie_clave;

                Log::info('🧠 ME_CLIENTE_KEYS_RESOLVED', [
                    'auth_uses' => 'USUARIOS.ID (JWT sub)',
                    'clie_uses' => 'CLIE03.CLAVE (identity.firebird_clie_clave)',
                    'firebird_id' => $usuario->ID,
                    'clie_clave' => $clieClave,
                    'clie_tabla' => $identity->firebird_clie_tabla ?? null,
                ]);

                if ($clieClave) {
                    try {
                        $connection = $this->firebirdService->getProductionConnection();

                        $clieRow = $connection->selectOne(
                            "SELECT * FROM CLIE03 WHERE CLAVE = ?",
                            [$clieClave]
                        );

                        Log::info('✅ ME_CLIENTE_CLIE03_DATA', [
                            'clie_clave' => $clieClave,
                            'clie_found' => $clieRow ? true : false,
                            'clie_nombre' => $clieRow->NOMBRE ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ ME_CLIENTE_DATA_ERROR', [
                            'clie_clave' => $clieClave,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ ME_CLIENTE_NO_CLIE_CLAVE', [
                        'identity_id' => $identity->id,
                    ]);
                }
            }

            $vendRow = null;

            if ($esVendedor) {
                $vendClave = $identity->firebird_vend_clave;

                Log::info('🧠 ME_VENDEDOR_KEYS_RESOLVED', [
                    'auth_uses'  => 'USUARIOS.ID (JWT sub)',
                    'vend_uses'  => 'VEND03.CVE_VEND (identity.firebird_vend_clave)',
                    'firebird_id' => $usuario->ID,
                    'vend_clave'  => $vendClave,
                    'vend_tabla'  => $identity->firebird_vend_tabla ?? null,
                ]);

                if ($vendClave) {
                    try {
                        $connection = $this->firebirdService->getProductionConnection();
                        $vendRow = $connection->selectOne(
                            "SELECT * FROM VEND03 WHERE CVE_VEND = ?",
                            [$vendClave]
                        );
                        Log::info('✅ ME_VENDEDOR_VEND03_DATA', [
                            'vend_clave'  => $vendClave,
                            'vend_found'  => (bool) $vendRow,
                            'vend_nombre' => $vendRow->NOMBRE ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ ME_VENDEDOR_DATA_ERROR', [
                            'vend_clave' => $vendClave,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ ME_VENDEDOR_NO_VEND_CLAVE', [
                        'identity_id' => $identity->id,
                    ]);
                }
            }

            $provRow = null;

            if ($esProveedor) {
                $provClave = $identity->firebird_prov_clave;

                Log::info('🧠 ME_PROVEEDOR_KEYS_RESOLVED', [
                    'auth_uses'  => 'USUARIOS.ID (JWT sub)',
                    'prov_uses'  => 'PROV03.CLAVE (identity.firebird_prov_clave)',
                    'firebird_id' => $usuario->ID,
                    'prov_clave'  => $provClave,
                    'prov_tabla'  => $identity->firebird_prov_tabla ?? null,
                ]);

                if ($provClave) {
                    try {
                        $connection = $this->firebirdService->getProductionConnection();
                        $provRow = $connection->selectOne(
                            "SELECT * FROM PROV03 WHERE CLAVE = ?",
                            [$provClave]
                        );

                        Log::info('✅ ME_PROVEEDOR_PROV03_DATA', [
                            'prov_clave'  => $provClave,
                            'prov_found'  => (bool) $provRow,
                            'prov_nombre' => $provRow->NOMBRE ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ ME_PROVEEDOR_DATA_ERROR', [
                            'prov_clave' => $provClave,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ ME_PROVEEDOR_NO_PROV_CLAVE', [
                        'identity_id' => $identity->id,
                    ]);
                }
            }

            $userPuesto = UserPuesto::with([
                'puesto',
                'area',
                'jefe.firebirdUser',
            ])
                ->where('user_firebird_identity_id', $identity->id)
                ->where('activo', true)
                ->first();

            return response()->json([
                'user' => new UsuarioResource($usuario, [
                    'user_puesto' => $userPuesto,
                    'departamentos'       => $departamentos,
                    'sl'                  => $slRow,
                    'vacaciones'          => $vcRow,
                    'historialvacaciones' => $hvcRow,
                    'faltas'              => $mfRow,
                    'acumuladosperiodos'  => $acRows,
                    'roles'               => $roles,
                    'TB'                  => $tbRow,
                    'CLIE'                => $clieRow,
                    'VEND'                => $vendRow,
                    'qr'                  => $qrData, // 👈 5. AGREGAR AL CONTEXTO
                    'firebird_tb_clave'   => $identity->firebird_tb_clave ?? null,
                    'firebird_clie_clave' => $identity->firebird_clie_clave ?? null,
                    'firebird_vend_clave' => $identity->firebird_vend_clave ?? null,
                    'turnoActivo'         => $turnoActivo,
                    'firebird_user_id'    => (int)$usuario->ID,
                    'usuarios_clave'      => (string)$usuario->CLAVE,
                    'identity_id'         => $identity->id,
                    'empresaNoi'          => $identity->firebird_empresa ?? null,
                    'tipo_usuario' => $esEmpleado ? 'empleado' : ($esCliente ? 'cliente' : ($esVendedor ? 'vendedor' : ($esProveedor ? 'proveedor' : null))),
                    'DEPTO_NOI'  => $deptoRow,
                    'PUESTO_NOI' => $puestoRow,
                    'PROV'                => $provRow,
                    'firebird_prov_clave' => $identity->firebird_prov_clave ?? null,
                ])
            ], 200);
        } catch (SignatureInvalidException $e) {
            Log::warning('Firma inválida', ['token_prefix' => substr($token, 0, 20)]);
            return response()->json(['message' => 'Token inválido'], 401);
        } catch (ExpiredException $e) {
            Log::warning('🔴 ME_TOKEN_EXPIRED', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'El token ha expirado'], 401);
        } catch (BeforeValidException $e) {
            return response()->json(['message' => 'Token no válido aún'], 401);
        } catch (UnexpectedValueException $e) {
            Log::warning('JWT malformado o inválido', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Token inválido'], 401);
        } catch (\Throwable $e) {
            Log::error('🔴 ME_FATAL_ERROR', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error de autenticación'
            ], 401);
        }
    }

    /**
     * Actualizar status del usuario
     */
    public function updateStatus(Request $request)
    {
        try {
            $request->validate([
                'status' => 'required|string',
            ]);
            $token = $request->bearerToken();
            if (! $token) {
                return response()->json(['message' => 'Token requerido'], 401);
            }
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));
            $usuario = Users::find($decoded->sub);
            if (! $usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }
            $usuario->status_id = $request->status;
            $usuario->save();
            return response()->json([
                'message' => 'Status actualizado',
                'user' => new UsuarioResource($usuario),
            ]);
        } catch (Exception $e) {
            Log::error('Error en updateStatus(): ' . $e->getMessage());

            return response()->json(['message' => 'Error al actualizar status'], 500);
        }
    }
}