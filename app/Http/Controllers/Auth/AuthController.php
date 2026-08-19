<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorAccessQrCodeResource;
use App\Http\Resources\UsuarioResource;
use App\Mail\ForgotPasswordMail;
use App\Models\Firebird\Users;
use App\Models\ModelHasRole;
use App\Models\UserFirebirdIdentity;
use App\Models\UserPuesto;
use App\Services\Checador\ChecadorQrService;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaManualService;
use Carbon\Carbon;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private $jwtSecret;

    private $jwtAlgorithm = 'HS256';

    private $jwtExpiration = 86400;

    private array $vipUserIds = [1315];

    protected FirebirdConnectionService $firebirdService;

    protected ChecadorQrService $qrService;

    public function __construct(
        FirebirdConnectionService $firebirdService,
        ChecadorQrService $qrService
    ) {
        $this->jwtSecret = config('jwt.secret');
        $this->firebirdService = $firebirdService;
        $this->qrService = $qrService;
    }

    /**
     * Iniciar sesión con correo y contraseña
     * - AUTH/JWT: USUARIOS.ID
     * - Relación NOI (TB/SL/VC/etc): USUARIOS.CLAVE
     */
    public function signIn(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $email = strtolower(trim($request->email));

            Log::info('🔍 LOGIN_ATTEMPT', [
                'email' => $email,
                'ip' => $request->ip(),
                'ua' => substr((string) $request->userAgent(), 0, 120),
            ]);

            // 🔹 Buscar usuario Firebird por CORREO
            $usuario = Users::whereRaw('LOWER(CORREO) = ?', [$email])->first();

            Log::info('👤 FIREBIRD_USER_LOOKUP', [
                'found' => $usuario ? true : false,
                'firebird_id' => $usuario->ID ?? null,
                'firebird_clave' => $usuario->CLAVE ?? null,
                'correo_db' => $usuario->CORREO ?? null,
                'nombre' => $usuario->NOMBRE ?? null,
            ]);

            if (! $usuario) {
                Log::warning('❌ LOGIN_FAIL_USER_NOT_FOUND', ['email' => $email]);

                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            // 🔐 Verificar password
            $match = Hash::check($request->password, $usuario->PASSWORD2);

            Log::info('🔐 FIREBIRD_PASSWORD_CHECK', [
                'firebird_id' => $usuario->ID,
                'match' => $match,
                'hash_length' => isset($usuario->PASSWORD2) ? strlen((string) $usuario->PASSWORD2) : null,
            ]);

            if (! $match) {
                Log::warning('❌ LOGIN_FAIL_BAD_PASSWORD', [
                    'firebird_id' => $usuario->ID,
                    'email' => $email,
                ]);

                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            // ✅ ID para sesión (JWT)
            $userId = (int) $usuario->ID;

            Log::info('🧠 LOGIN_AUTH_KEY', [
                'auth_uses' => 'USUARIOS.ID',
                'firebird_id' => $userId,
                'type_id' => gettype($userId),
            ]);

            // 🔹 Pivote MySQL (roles/empresa)
            $identity = UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->ID)->first();

            // 🧯 Fallback legacy
            if (! $identity) {
                $identityLegacy = UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();
                $identity = $identityLegacy;
            }

            Log::info('📌 MYSQL_IDENTITY_LOOKUP', [
                'found' => $identity ? true : false,
                'identity_id' => $identity->id ?? null,
                'identity_firebird_user_clave' => $identity->firebird_user_clave ?? null,
                'identity_firebird_tb_clave' => $identity->firebird_tb_clave ?? null,
                'identity_firebird_clie_clave' => $identity->firebird_clie_clave ?? null,
                'identity_empresa' => $identity->firebird_empresa ?? null,
                'identity_tb_tabla' => $identity->firebird_tb_tabla ?? null,
                'identity_clie_tabla' => $identity->firebird_clie_tabla ?? null,
                'firebird_vend_clave' => null,
            ]);

            $roles = collect();
            $qrData = null;
            if ($identity) {
                $roles = $identity->roles()->get();

                try {
                    $qr = $this->qrService->generar($identity->id);
                    $qrData = (new ChecadorAccessQrCodeResource($qr))->resolve();
                } catch (\Throwable $e) {
                    Log::warning('⚠️ LOGIN_QR_NO_GENERADO', [
                        'identity_id' => $identity->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $userPuesto = null;
            $esJefeAuxiliar = false;

            if ($identity) {
                $identity = UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->ID)
                    ->with([
                        'puestoActivo.puesto',
                        'puestoActivo.area',
                        'puestoActivo.jefe.firebirdUser',
                    ])
                    ->first();

                $userPuesto = $identity->puestoActivo ?? null;

                // 👇 nuevo: ¿eres jefe_aux_id de ALGÚN puesto activo (de otro colaborador)?
                $esJefeAuxiliar = UserPuesto::where('jefe_aux_id', $identity->id)
                    ->where('activo', 1)
                    ->exists();
            }

            Log::info('🎭 MYSQL_ROLES', [
                'identity_id' => $identity->id ?? null,
                'roles_count' => $roles->count(),
                'roles' => $roles->pluck('name')->values()->all(),
            ]);

            // 🎯 Determinar tipo de usuario
            $esEmpleado = $identity && $identity->firebird_tb_clave !== null;
            $esCliente = $identity && $identity->firebird_clie_clave !== null;
            $esVendedor = $identity && $identity->firebird_vend_clave !== null;
            $esProveedor = $identity && $identity->firebird_prov_clave !== null;

            Log::info('🔍 USER_TYPE_DETECTION', [
                'es_empleado' => $esEmpleado,
                'es_cliente' => $esCliente,
            ]);

            // ✅ JWT payload blindado con claims estándar
            $payload = [
                'sub' => $userId,
                // 'correo'  => $usuario->CORREO,
                // 'usuario' => $usuario->USUARIO,
                'iat' => time(),
                // 'exp' => time() + $this->jwtExpiration,
                'iss' => config('app.url'),
                'aud' => 'fibrasan',
                'jti' => Str::random(32),
            ];

            $esVip = in_array($userId, $this->vipUserIds, true);

            if (! $esVip) {
                $payload['exp'] = time() + $this->jwtExpiration;
            } else {
                Log::info('♾️ JWT_VIP_NO_EXPIRATION', ['firebird_id' => $userId]);
            }

            Log::info('✅ JWT_SUB_IS_ID', [
                'payload_sub' => $payload['sub'],
                'usuario_id' => $usuario->ID,
            ]);

            $key = config('jwt.secret');

            if (! is_string($key) || empty($key)) {
                Log::error('JWT secret missing or empty', [
                    'jwt_secret_env' => env('JWT_SECRET'),
                    'jwt_secret_config' => $key,
                ]);

                return response()->json(['message' => 'Error de configuración interna'], 500);
            }

            $token = JWT::encode($payload, $key, 'HS256');
            $connection = $this->firebirdService->getProductionConnection();
            $departamentos = collect();
            $slRow = null;
            $vcRow = null;
            $hvcRow = null;
            $mfRow = null;
            $acRows = collect();
            $tbRow = null;
            $deptoRow = null;
            $puestoRow = null;
            $turnoActivo = null;
            $clieRow = null;

            // =====================================================
            // 🏢 EMPLEADOS: Datos NOI usando TB.CLAVE
            // =====================================================
            if ($esEmpleado) {
                $tbClave = $identity->firebird_tb_clave ?? null;
                $tbClaveNorm = is_string($tbClave) ? trim($tbClave) : $tbClave;
                $empresaNoi = $identity->firebird_empresa ?? '04';

                Log::info('🏢 EMPLEADO_NOI_CONTEXT', [
                    'empresaNoi' => $empresaNoi,
                    'tb_clave_for_NOI' => $tbClaveNorm,
                    'usuarios_clave' => $usuario->CLAVE,
                    'will_query_noi' => (bool) $tbClaveNorm,
                ]);

                if ($tbClaveNorm) {
                    try {
                        $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                        // TB (base) - usando TB.CLAVE
                        $tb = $firebirdNoi->getOperationalTable('TB')
                            ->keyBy(fn ($row) => trim((string) $row->CLAVE));
                        $tbRow = $tb[$tbClaveNorm] ?? null;

                        Log::info('📘 NOI_TB_LOOKUP', [
                            'tb_clave' => $tbClaveNorm,
                            'tb_found' => $tbRow ? true : false,
                        ]);

                        // 🚫 Bloquear login si el empleado está dado de baja (STATUS = 'B')
                        // if ($tbRow && isset($tbRow->STATUS) && trim((string)$tbRow->STATUS) === 'B') {
                        //     Log::warning('🚫 LOGIN_BLOCKED_STATUS_BAJA', [
                        //         'firebird_id' => $userId,
                        //         'tb_clave'    => $tbClaveNorm,
                        //         'status'      => $tbRow->STATUS,
                        //     ]);

                        //     return response()->json([
                        //         'message' => 'Tu usuario ha sido dado de baja y no puedes ingresar.'
                        //     ], 403);
                        // }

                        // 🆕 Si encontramos al empleado en TB, buscamos DEPTO y PUESTO
                        if ($tbRow) {

                            // 🏢 DEPTO -> tabla maestra DEPTOS + empresa (ej. DEPTOS04)
                            $deptoClave = isset($tbRow->DEPTO) ? trim((string) $tbRow->DEPTO) : null;

                            if ($deptoClave) {
                                try {
                                    $deptos = $firebirdNoi->getMasterTable('DEPTOS') // 🔥 antes: getOperationalTable
                                        ->keyBy(fn ($row) => trim((string) $row->CLAVE));
                                    $deptoRow = $deptos[$deptoClave] ?? null;

                                    Log::info('🏢 NOI_DEPTO_LOOKUP', [
                                        'empresa' => $empresaNoi,
                                        'depto_clave' => $deptoClave,
                                        'depto_found' => (bool) $deptoRow,
                                        'depto_nombre' => $deptoRow->NOMBRE ?? null,
                                    ]);
                                } catch (\Throwable $e) {
                                    Log::error('⚠️ NOI_DEPTO_LOOKUP_ERROR', [
                                        'depto_clave' => $deptoClave,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            } else {
                                Log::warning('⚠️ NOI_DEPTO_SKIPPED_TB_SIN_DEPTO', [
                                    'tb_clave' => $tbClaveNorm,
                                ]);
                            }

                            // 👔 PUESTO -> tabla maestra PUESTOS + empresa (ej. PUESTOS04)
                            $puestoClave = isset($tbRow->PUESTO) ? trim((string) $tbRow->PUESTO) : null;

                            if ($puestoClave) {
                                try {
                                    $puestos = $firebirdNoi->getMasterTable('PUESTOS') // 🔥 antes: getOperationalTable
                                        ->keyBy(fn ($row) => trim((string) $row->CLAVE));
                                    $puestoRow = $puestos[$puestoClave] ?? null;

                                    Log::info('👔 NOI_PUESTO_LOOKUP', [
                                        'empresa' => $empresaNoi,
                                        'puesto_clave' => $puestoClave,
                                        'puesto_found' => (bool) $puestoRow,
                                        'puesto_nombre' => $puestoRow->NOMBRE ?? null,
                                    ]);
                                } catch (\Throwable $e) {
                                    Log::error('⚠️ NOI_PUESTO_LOOKUP_ERROR', [
                                        'puesto_clave' => $puestoClave,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            } else {
                                Log::warning('⚠️ NOI_PUESTO_SKIPPED_TB_SIN_PUESTO', [
                                    'tb_clave' => $tbClaveNorm,
                                ]);
                            }
                        }

                        // SL - usando TB.CLAVE
                        $sl = $firebirdNoi->getOperationalTable('SL')
                            ->keyBy(fn ($row) => trim((string) $row->CLAVE_TRAB));
                        $slRow = $sl[$tbClaveNorm] ?? null;

                        Log::info('💰 NOI_SL_LOOKUP', [
                            'tb_clave' => $tbClaveNorm,
                            'sl_found' => $slRow ? true : false,
                        ]);

                        // VC - usando TB.CLAVE
                        $vc = $firebirdNoi->getOperationalTable('VC')
                            ->keyBy(fn ($row) => trim((string) $row->CLAVE_TRAB));
                        $vcRow = $vc[$tbClaveNorm] ?? null;

                        Log::info('🏖️ NOI_VC_LOOKUP', [
                            'tb_clave' => $tbClaveNorm,
                            'vc_found' => $vcRow ? true : false,
                        ]);

                        // Turno
                        $turnoActivo = $identity->turnoActivo()
                            ->with(['turno.turnoDias', 'status'])
                            ->first();

                        Log::info('✅ EMPLEADO_NOI_DATA_OK', [
                            'tb_clave' => $tbClaveNorm,
                            'has_tb' => (bool) $tbRow,
                            'has_sl' => (bool) $slRow,
                            'has_vc' => (bool) $vcRow,
                            'has_depto' => (bool) $deptoRow,
                            'has_puesto' => (bool) $puestoRow,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ EMPLEADO_NOI_DATA_ERROR', [
                            'empresa' => $empresaNoi,
                            'tb_clave' => $tbClaveNorm,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ EMPLEADO_NOI_SKIPPED_NO_TB_CLAVE', [
                        'firebird_id' => $userId,
                        'identity_id' => $identity->id ?? null,
                    ]);
                }
            }

            // =====================================================
            // 🛒 CLIENTES: Datos de CLIE03
            // =====================================================
            if ($esCliente) {
                $clieClave = $identity->firebird_clie_clave ?? null;

                Log::info('🛒 CLIENTE_CONTEXT', [
                    'clie_clave' => $clieClave,
                    'clie_tabla' => $identity->firebird_clie_tabla ?? null,
                ]);

                if ($clieClave) {
                    try {
                        $clieRow = $connection->selectOne(
                            'SELECT * FROM CLIE03 WHERE CLAVE = ?',
                            [$clieClave]
                        );

                        Log::info('🛒 CLIE03_LOOKUP', [
                            'clie_clave' => $clieClave,
                            'clie_found' => $clieRow ? true : false,
                            'clie_nombre' => $clieRow->NOMBRE ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ CLIENTE_DATA_ERROR', [
                            'clie_clave' => $clieClave,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ CLIENTE_SKIPPED_NO_CLIE_CLAVE', [
                        'firebird_id' => $userId,
                        'identity_id' => $identity->id ?? null,
                    ]);
                }
            }

            // =====================================================
            // 🛒 VENDEDORES: Datos de VEND03
            // =====================================================
            $vendRow = null;

            if ($esVendedor) {
                $vendClave = $identity->firebird_vend_clave ?? null;

                Log::info('🧑‍💼 VENDEDOR_CONTEXT', [
                    'vend_clave' => $vendClave,
                    'vend_tabla' => $identity->firebird_vend_tabla ?? null,
                ]);

                if ($vendClave) {
                    try {
                        $vendRow = $connection->selectOne(
                            'SELECT * FROM VEND03 WHERE CVE_VEND = ?',
                            [$vendClave]
                        );

                        Log::info('🧑‍💼 VEND03_LOOKUP', [
                            'vend_clave' => $vendClave,
                            'vend_found' => $vendRow ? true : false,
                            'vend_nombre' => $vendRow->NOMBRE ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ VENDEDOR_DATA_ERROR', [
                            'vend_clave' => $vendClave,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ VENDEDOR_SKIPPED_NO_VEND_CLAVE', [
                        'firebird_id' => $userId,
                        'identity_id' => $identity->id ?? null,
                    ]);
                }
            }

            // =====================================================
            // 📦 PROVEEDORES: Datos de PROV03
            // =====================================================
            $provRow = null;
            if ($esProveedor) {
                $provClave = $identity->firebird_prov_clave ?? null;

                Log::info('📦 PROVEEDOR_CONTEXT', [
                    'prov_clave' => $provClave,
                    'prov_tabla' => $identity->firebird_prov_tabla ?? null,
                ]);

                if ($provClave) {
                    try {
                        $provRow = $connection->selectOne(
                            'SELECT * FROM PROV03 WHERE CLAVE = ?',
                            [$provClave]
                        );

                        Log::info('📦 PROV03_LOOKUP', [
                            'prov_clave' => $provClave,
                            'prov_found' => (bool) $provRow,
                            'prov_nombre' => $provRow->NOMBRE ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('⚠️ PROVEEDOR_DATA_ERROR', [
                            'prov_clave' => $provClave,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ PROVEEDOR_SKIPPED_NO_PROV_CLAVE', [
                        'firebird_id' => $userId,
                        'identity_id' => $identity->id ?? null,
                    ]);
                }
            }
            Log::info('✅ LOGIN_SUCCESS', [
                'firebird_id' => $userId,
                'tipo_usuario' => $esEmpleado ? 'EMPLEADO' : ($esCliente ? 'CLIENTE' : ($esVendedor ? 'VENDEDOR' : ($esProveedor ? 'PROVEEDOR' : 'INDEFINIDO'))), // 🆕
                'identity_id' => $identity->id ?? null,
            ]);

            return response()->json([
                'encrypt' => $token,
                'user' => new UsuarioResource($usuario, [
                    'user_puesto' => $userPuesto,
                    'es_jefe_auxiliar' => $esJefeAuxiliar,
                    'identity_id' => $identity->id ?? null,
                    'departamentos' => $departamentos,
                    'sl' => $slRow,
                    'vacaciones' => $vcRow,
                    'historialvacaciones' => $hvcRow,
                    'faltas' => $mfRow,
                    'acumuladosperiodos' => $acRows,
                    'roles' => $roles,
                    'TB' => $tbRow,
                    'CLIE' => $clieRow,
                    'VEND' => $vendRow,
                    'qr' => $qrData,
                    'firebird_user_id' => $userId,
                    'firebird_user_clave' => $identity->firebird_tb_clave ?? null,
                    'firebird_clie_clave' => $identity->firebird_clie_clave ?? null,
                    'firebird_vend_clave' => $identity->firebird_vend_clave ?? null,
                    'PROV' => $provRow,
                    'firebird_prov_clave' => $identity->firebird_prov_clave ?? null,
                    'tipo_usuario' => $esEmpleado ? 'empleado' : ($esCliente ? 'cliente' : ($esVendedor ? 'vendedor' : ($esProveedor ? 'proveedor' : null))),
                    'turnoActivo' => $turnoActivo,
                    'DEPTO_NOI' => $deptoRow,
                    'PUESTO_NOI' => $puestoRow,
                ]),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('💥 Error en signIn()', [
                'error' => $e->getMessage(),
                // 'trace' => $e->getTraceAsString()  // descomenta solo en dev
            ]);

            return response()->json([
                'message' => 'Error al iniciar sesión',
            ], 500);
        }
    }

    /**
     * Iniciar sesión usando token (refresh)
     * 🔥 CORRECCIÓN: Eliminar validación de status_id
     */
    public function signInWithToken(Request $request)
    {
        try {
            $token = $request->input('encrypt');

            if (! $token) {
                return response()->json([
                    'message' => 'Token no proporcionado',
                ], 401);
            }

            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            $usuario = Users::find($decoded->sub);

            // 🔥 CORRECCIÓN: Solo verificar si existe el usuario
            if (! $usuario) {
                return response()->json([
                    'message' => 'Usuario no válido',
                ], 401);
            }

            $newToken = $this->generateToken($usuario);

            return response()->json([
                'user' => [
                    'id' => $usuario->CLAVE,
                    'name' => $usuario->NOMBRE,
                    'email' => $usuario->CORREO,
                    'usuario' => $usuario->USUARIO,
                    'status' => $usuario->STATUS,
                    'depto' => $usuario->DEPTO,
                    'departamento' => $usuario->DEPARTAMENTO,
                    'direccion_id' => $usuario->direccion_id,
                    'photo' => $usuario->PHOTO,
                ],
                'encrypt' => $newToken,
                'token_type' => 'Bearer',
                'expires_in' => in_array((int) $usuario->CLAVE, $this->vipUserIds, true) ? null : 86400,
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en signInWithToken: '.$e->getMessage());

            return response()->json([
                'message' => 'Token inválido o expirado',
            ], 401);
        }
    }

    /**
     * Generar token JWT
     */
    private function generateToken(Users $usuario)
    {
        $issuedAt = time();

        $payload = [
            'iss' => env('APP_URL', 'http://localhost'),
            'sub' => $usuario->CLAVE,
            'iat' => $issuedAt,
            'correo' => $usuario->CORREO,
            'usuario' => $usuario->USUARIO,
        ];

        if (! in_array((int) $usuario->CLAVE, $this->vipUserIds, true)) {
            $payload['exp'] = $issuedAt + $this->jwtExpiration;
        }

        return JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);
    }

    /**
     * Sign up - Registrarse
     */
    public function signUp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,correo',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
            }

            $usuario = Users::create([
                'NOMBRE' => $request->name,
                'CORREO' => $request->email,
                'PASSWORD2' => Hash::make($request->password),
                'PHOTO' => 'photos/users.jpg',
            ]);

            // Crear registro en model_has_roles
            ModelHasRole::create([
                'ROLE_CLAVE' => 1, // ID del rol COLABORADOR
                'MODEL_CLAVE' => $usuario->CLAVE,
                'SUBROL_ID' => null,
                'MODEL_TYPE' => Users::class,
            ]);

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'user' => [
                    'id' => $usuario->CLAVE,
                    'name' => $usuario->NOMBRE,
                    'email' => $usuario->CORREO,
                ],
            ], 201);
        } catch (Exception $e) {
            Log::error('Error en signUp: '.$e->getMessage());

            return response()->json(['message' => 'Error al crear usuario'], 500);
        }
    }

    /**
     * Sign out - Cerrar sesión
     */
    public function signOut(Request $request)
    {
        try {
            return response()->json([
                'message' => 'Sesión cerrada exitosamente',
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en signOut: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al cerrar sesión',
            ], 500);
        }
    }

    /**
     * Forgot password - Recuperar contraseña
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Email inválido'], 422);
            }

            $usuario = Users::where('correo', $request->email)->first();

            if (! $usuario) {
                return response()->json(['message' => 'Si el email existe, recibirás instrucciones'], 200);
            }

            $token = Str::random(60);

            DB::table('password_resets')->where('usuario_id', $usuario->id)->delete();

            DB::table('password_resets')->insert([
                'email' => $usuario->correo,
                'token' => $token,
                'usuario_id' => $usuario->id,
                'created_at' => Carbon::now(),
            ]);

            Mail::to($usuario->correo)->send(new ForgotPasswordMail($token, $usuario->correo, $usuario));

            return response()->json(['message' => 'Si el email existe, recibirás instrucciones'], 200);
        } catch (Exception $e) {
            Log::error('Error en forgotPassword: '.$e->getMessage());

            return response()->json(['message' => 'Error al procesar solicitud'], 500);
        }
    }

    /**
     * Reset password - Restablecer contraseña
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos inválidos'], 422);
            }

            $record = DB::table('password_resets')->where('token', $request->token)->first();

            if (! $record) {
                return response()->json(['message' => 'Token inválido o expirado'], 400);
            }

            $usuario = Users::find($record->usuario_id);

            if (! $usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }

            $usuario->password = Hash::make($request->password);
            $usuario->save();

            DB::table('password_resets')->where('token', $request->token)->delete();

            return response()->json(['message' => 'Contraseña actualizada exitosamente'], 200);
        } catch (Exception $e) {
            Log::error('Error en resetPassword: '.$e->getMessage());

            return response()->json(['message' => 'Error al restablecer contraseña'], 500);
        }
    }

    /**
     * Unlock session - Desbloquear sesión
     * 🔥 CORRECCIÓN: Cambiar PASSWORD por password
     */
    public function unlockSession(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos inválidos'], 422);
            }

            $usuario = Users::where('correo', $request->email)->first();

            // 🔥 CORRECCIÓN: Validar contra 'password' en minúscula
            if (! $usuario || ! Hash::check($request->password, $usuario->password)) {
                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            $token = $this->generateToken($usuario);

            return response()->json(['encrypt' => $token], 200);
        } catch (Exception $e) {
            Log::error('Error en unlockSession: '.$e->getMessage());

            return response()->json(['message' => 'Error al desbloquear sesión'], 500);
        }
    }
}
