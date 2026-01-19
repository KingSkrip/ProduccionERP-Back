<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\UsuarioResource;
use App\Mail\ForgotPasswordMail;
use App\Models\Firebird\Users;
use App\Models\ModelHasRole;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';
    private $jwtExpiration = 86400; // 24 horas

    public function __construct()
    {
        $this->jwtSecret = env('JWT_SECRET');
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
                'password' => 'required|string'
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

            if (!$usuario) {
                Log::warning('❌ LOGIN_FAIL_USER_NOT_FOUND', ['email' => $email]);
                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            // 🔐 Verificar password
            $match = Hash::check($request->password, $usuario->PASSWORD2);

            Log::info('🔐 FIREBIRD_PASSWORD_CHECK', [
                'firebird_id' => $usuario->ID,
                'match' => $match,
                'hash_length' => isset($usuario->PASSWORD2) ? strlen((string)$usuario->PASSWORD2) : null,
            ]);

            if (!$match) {
                Log::warning('❌ LOGIN_FAIL_BAD_PASSWORD', [
                    'firebird_id' => $usuario->ID,
                    'email' => $email
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
            $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first();

            Log::info('📌 MYSQL_IDENTITY_LOOKUP', [
                'found' => $identity ? true : false,
                'identity_id' => $identity->id ?? null,
                'identity_firebird_user_clave' => $identity->firebird_user_clave ?? null,
                'identity_firebird_tb_clave' => $identity->firebird_tb_clave ?? null,
                'identity_empresa' => $identity->firebird_empresa ?? null,
                'identity_tb_tabla' => $identity->firebird_tb_tabla ?? null,
            ]);

            $roles = collect();
            if ($identity) {
                $roles = $identity->roles()->get();
            }

            Log::info('🎭 MYSQL_ROLES', [
                'identity_id' => $identity->id ?? null,
                'roles_count' => $roles->count(),
                'roles' => $roles->pluck('name')->values()->all(),
            ]);

            // ✅ JWT sub = USUARIOS.ID
            $payload = [
                'sub'     => $userId,
                'correo'  => $usuario->CORREO,
                'usuario' => $usuario->USUARIO,
                'iat'     => time(),
                'exp'     => time() + 86400
            ];

            Log::info('✅ JWT_SUB_IS_ID', [
                'payload_sub' => $payload['sub'],
                'usuario_id' => $usuario->ID,
            ]);

            $key = config('jwt.secret');

            if (!is_string($key) || $key === '') {
                Log::error('JWT secret missing (config jwt.secret)', [
                    'jwt_secret_env' => env('JWT_SECRET'),
                    'jwt_secret_config' => $key,
                ]);
                return response()->json(['message' => 'JWT secret no configurado'], 500);
            }
            
            $token = JWT::encode($payload, $key, 'HS256');
            

            // 🔥 Datos NOI usando TB.CLAVE (de identity)
            $departamentos = collect();
            $slRow = null;
            $vcRow = null;
            $hvcRow = null;
            $mfRow = null;
            $acRows = collect();
            $tbRow = null;
            $turnoActivo = null;

            // ✅ Obtener TB.CLAVE desde identity (NO USUARIOS.CLAVE)
            $tbClave = $identity->firebird_tb_clave ?? null;
            $tbClaveNorm = is_string($tbClave) ? trim($tbClave) : $tbClave;
            $empresaNoi = $identity->firebird_empresa ?? '04';

            Log::info('🏢 NOI_CONTEXT', [
                'empresaNoi' => $empresaNoi,
                'tb_clave_for_NOI' => $tbClaveNorm,
                'usuarios_clave' => $usuario->CLAVE, // solo para comparar en log
                'will_query_noi' => (bool) $tbClaveNorm,
            ]);

            if ($tbClaveNorm) {
                try {
                    $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                    // TB (base) - usando TB.CLAVE
                    $tb = $firebirdNoi->getOperationalTable('TB')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE));
                    $tbRow = $tb[$tbClaveNorm] ?? null;

                    Log::info('📘 NOI_TB_LOOKUP', [
                        'tb_clave' => $tbClaveNorm,
                        'tb_found' => $tbRow ? true : false,
                    ]);

                    // SL - usando TB.CLAVE
                    $sl = $firebirdNoi->getOperationalTable('SL')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE_TRAB));
                    $slRow = $sl[$tbClaveNorm] ?? null;

                    Log::info('💰 NOI_SL_LOOKUP', [
                        'tb_clave' => $tbClaveNorm,
                        'sl_found' => $slRow ? true : false,
                    ]);

                    // VC - usando TB.CLAVE
                    $vc = $firebirdNoi->getOperationalTable('VC')
                        ->keyBy(fn($row) => trim((string)$row->CLAVE_TRAB));
                    $vcRow = $vc[$tbClaveNorm] ?? null;

                    Log::info('🏖️ NOI_VC_LOOKUP', [
                        'tb_clave' => $tbClaveNorm,
                        'vc_found' => $vcRow ? true : false,
                    ]);

                    // Turno
                    if ($identity) {
                        $turnoActivo = $identity->turnoActivo()
                            ->with(['turno.turnoDias', 'status'])
                            ->first();
                    }

                    Log::info('✅ NOI_DATA_OK', [
                        'tb_clave' => $tbClaveNorm,
                        'has_tb' => (bool) $tbRow,
                        'has_sl' => (bool) $slRow,
                        'has_vc' => (bool) $vcRow,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('⚠️ NOI_DATA_ERROR', [
                        'empresa' => $empresaNoi,
                        'tb_clave' => $tbClaveNorm,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning('⚠️ NOI_SKIPPED_NO_TB_CLAVE', [
                    'firebird_id' => $userId,
                    'identity_id' => $identity->id ?? null,
                    'identity_tb_clave' => $identity->firebird_tb_clave ?? null,
                ]);
            }

            Log::info('✅ LOGIN_SUCCESS', [
                'firebird_id' => $userId,
                'tb_clave' => $tbClaveNorm,
                'identity_id' => $identity->id ?? null,
            ]);

            return response()->json([
                'encrypt' => $token,
                'user' => new UsuarioResource($usuario, [
                    'departamentos' => $departamentos,
                    'sl' => $slRow,
                    'vacaciones' => $vcRow,
                    'historialvacaciones' => $hvcRow,
                    'faltas' => $mfRow,
                    'acumuladosperiodos' => $acRows,
                    'roles' => $roles,
                    'TB' => $tbRow,

                    // 🧾 para debug/response
                    'firebird_user_id' => $userId,           // ✅ AUTH (JWT sub)
                    'firebird_user_clave' => $tbClaveNorm,   // ✅ NOI (TB.CLAVE)
                    'turnoActivo' => $turnoActivo,
                ])
            ], 200);
        } catch (\Throwable $e) {
            Log::error('💥 Error en signIn()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'email' => $request->email ?? null
            ]);

            return response()->json([
                'message' => 'Error al iniciar sesión',
                'debug' => config('app.debug') ? $e->getMessage() : null
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

            if (!$token) {
                return response()->json([
                    'message' => 'Token no proporcionado'
                ], 401);
            }

            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            $usuario = Users::find($decoded->sub);

            // 🔥 CORRECCIÓN: Solo verificar si existe el usuario
            if (!$usuario) {
                return response()->json([
                    'message' => 'Usuario no válido'
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
                    'photo' => $usuario->PHOTO
                ],
                'encrypt' => $newToken,
                'token_type'  => 'Bearer',
                'expires_in'  => 86400
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en signInWithToken: ' . $e->getMessage());
            return response()->json([
                'message' => 'Token inválido o expirado'
            ], 401);
        }
    }

    /**
     * Generar token JWT
     */
    private function generateToken(Users $usuario)
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $this->jwtExpiration;

        $payload = [
            'iss' => env('APP_URL', 'http://localhost'),
            'sub' => $usuario->CLAVE,
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'correo' => $usuario->CORREO,
            'usuario' => $usuario->USUARIO,
        ];

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
                ]
            ], 201);
        } catch (Exception $e) {
            Log::error('Error en signUp: ' . $e->getMessage());
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
                'message' => 'Sesión cerrada exitosamente'
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en signOut: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al cerrar sesión'
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
                'email' => 'required|email'
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Email inválido'], 422);
            }

            $usuario = Users::where('correo', $request->email)->first();

            if (!$usuario) {
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
            Log::error('Error en forgotPassword: ' . $e->getMessage());
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
                'password' => 'required|string|min:6'
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos inválidos'], 422);
            }

            $record = DB::table('password_resets')->where('token', $request->token)->first();

            if (!$record) {
                return response()->json(['message' => 'Token inválido o expirado'], 400);
            }

            $usuario = Users::find($record->usuario_id);

            if (!$usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }

            $usuario->password = Hash::make($request->password);
            $usuario->save();

            DB::table('password_resets')->where('token', $request->token)->delete();

            return response()->json(['message' => 'Contraseña actualizada exitosamente'], 200);
        } catch (Exception $e) {
            Log::error('Error en resetPassword: ' . $e->getMessage());
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
                'password' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos inválidos'], 422);
            }

            $usuario = Users::where('correo', $request->email)->first();

            // 🔥 CORRECCIÓN: Validar contra 'password' en minúscula
            if (!$usuario || !Hash::check($request->password, $usuario->password)) {
                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            $token = $this->generateToken($usuario);

            return response()->json(['encrypt' => $token], 200);
        } catch (Exception $e) {
            Log::error('Error en unlockSession: ' . $e->getMessage());
            return response()->json(['message' => 'Error al desbloquear sesión'], 500);
        }
    }
}
