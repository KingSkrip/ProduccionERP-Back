<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Users;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\UsuarioResource;
use App\Mail\ForgotPasswordMail;
use App\Models\ModelHasRole;
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
     */
    public function signIn(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $usuario = Users::where('correo', $request->email)->first();

            if (!$usuario || !Hash::check($request->password, $usuario->password)) {
                return response()->json([
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            $token = $this->generateToken($usuario);

            return response()->json([
                'accessToken' => $token,
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en signIn: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al iniciar sesión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Iniciar sesión usando token (refresh)
     */
    public function signInWithToken(Request $request)
    {
        try {
            $token = $request->input('accessToken');

            if (!$token) {
                return response()->json([
                    'message' => 'Token no proporcionado'
                ], 401);
            }

            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            $usuario = Users::find($decoded->sub);

            if (!$usuario) {
                return response()->json([
                    'message' => 'Usuario no válido'
                ], 401);
            }

            $newToken = $this->generateToken($usuario);

            return response()->json([
                'accessToken' => $newToken,
                'user' => [
                    'nombre' => $usuario->nombre,
                    'correo' => $usuario->correo,
                    'usuario' => $usuario->usuario,
                    'status_id' => $usuario->status_id,
                    'departamento_id' => $usuario->departamento_id,
                    'direccion_id' => $usuario->direccion_id,
                    'photo' => $usuario->photo
                ]
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
            'sub' => $usuario->id,
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'correo' => $usuario->correo,
            'usuario' => $usuario->usuario,
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
                'nombre' => $request->name,
                'correo' => $request->email,
                'password' => Hash::make($request->password),
                'photo' => 'photos/users.jpg',
            ]);

            // Crear registro en model_has_roles
            ModelHasRole::create([
                'role_clave' => 3, // ID del rol COLABORADOR
                'model_clave' => $usuario->id,
                'subrol_id' => null, // si no hay subrol inicial
                'model_type' => Users::class,
            ]);

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nombre,
                    'email' => $usuario->correo,
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
        // Opcional: guardar token en tabla de revocados
        // DB::table('revoked_tokens')->insert(['token' => $request->bearerToken(), 'revoked_at' => now()]);

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
                // No revelar si el email existe
                return response()->json(['message' => 'Si el email existe, recibirás instrucciones'], 200);
            }

            // 🔥 Generar token
            $token = Str::random(60);

            // Eliminar tokens previos
            DB::table('password_resets')->where('usuario_id', $usuario->id)->delete();

            // Insertar token nuevo
            DB::table('password_resets')->insert([
                'email' => $usuario->correo,
                'token' => $token,
                'usuario_id' => $usuario->id,
                'created_at' => Carbon::now(),
            ]);

            // Enviar correo
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

            // Actualizar contraseña
            $usuario->password = Hash::make($request->password);
            $usuario->save();

            // Eliminar token usado
            DB::table('password_resets')->where('token', $request->token)->delete();

            return response()->json(['message' => 'Contraseña actualizada exitosamente'], 200);
        } catch (Exception $e) {
            Log::error('Error en resetPassword: ' . $e->getMessage());
            return response()->json(['message' => 'Error al restablecer contraseña'], 500);
        }
    }

    /**
     * Unlock session - Desbloquear sesión
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

            if (!$usuario || !Hash::check($request->password, $usuario->password)) {
                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            $token = $this->generateToken($usuario);

            return response()->json(['accessToken' => $token], 200);
        } catch (Exception $e) {
            Log::error('Error en unlockSession: ' . $e->getMessage());
            return response()->json(['message' => 'Error al desbloquear sesión'], 500);
        }
    }
}

//Kingskrip132/*