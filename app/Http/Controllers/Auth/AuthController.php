<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\UsuarioResource;
use App\Mail\ForgotPasswordMail;
use App\Models\PasswordReset;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';
    private $jwtExpiration = 86400; // 24 horas en segundos

    public function __construct()
    {
        // Obtener la clave secreta del .env
        $this->jwtSecret = env('JWT_SECRET');
    }

    /**
     * Sign in - Iniciar sesión
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
            $usuario = Usuario::where('CORREO', $request->email)->first();
            if (!$usuario) {
                return response()->json([
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }
            if (!Hash::check($request->password, $usuario->PASSWORD2)) {
                return response()->json([
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            // Verificar status del usuario
            // if ($usuario->STATUS != 'A') {
            //     return response()->json([
            //         'message' => 'Usuario inactivo'
            //     ], 403);
            // }

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
     * Sign in using token - Iniciar sesión con token
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

            // Decodificar y validar token
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            // Buscar usuario
            $usuario = Usuario::where('CLAVE', $decoded->sub)->first();

            if (!$usuario || $usuario->STATUS != 'A') {
                return response()->json([
                    'message' => 'Usuario no válido'
                ], 401);
            }

            // Generar nuevo token (refresh)
            $newToken = $this->generateToken($usuario);

            // Preparar datos del usuario
            $userData = [
                'id' => $usuario->CLAVE,
                'name' => $usuario->NOMBRE,
                'email' => $usuario->CORREO,
                'usuario' => $usuario->USUARIO,
                'perfil' => $usuario->PERFIL,
                'depto' => $usuario->DEPTO,
                'departamento' => $usuario->DEPARTAMENTO,
                'almacen' => $usuario->ALMACEN,
                'status' => $usuario->STATUS,
            ];

            return response()->json([
                'accessToken' => $newToken,
                'user' => $userData
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en signInWithToken: ' . $e->getMessage());
            return response()->json([
                'message' => 'Token inválido o expirado'
            ], 401);
        }
    }

    /**
     * Sign up - Registrarse
     */
    public function signUp(Request $request)
    {
        try {
            // Validar datos con mensajes personalizados
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:"USUARIOS",CORREO',
                    'password' => 'required|string|min:6',
                ],
                [
                    'name.required' => 'El nombre es obligatorio.',
                    'name.string' => 'El nombre debe ser un texto.',
                    'name.max' => 'El nombre no debe exceder 255 caracteres.',
                    'email.required' => 'El correo es obligatorio.',
                    'email.email' => 'El correo no tiene un formato válido.',
                    'email.unique' => 'El correo ya está registrado.',
                    'password.required' => 'La contraseña es obligatoria.',
                    'password.string' => 'La contraseña debe ser un texto.',
                    'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Generar CLAVE única
            $clave = $this->generateUniqueClave();

            $usuario = Usuario::create([
                'CLAVE' => $clave,
                'NOMBRE' => $request->name,
                'USUARIO' => "COLABORADOR",
                'CORREO' => $request->email,
                'PASSWORD2' => Hash::make($request->password),
                'PERFIL' => 0,
                'SESIONES' => 0,
                'VERSION' => 1,
                'FECHAACT' => now(),
                'DEPTO' => 0,
                'DEPARTAMENTO' => '',
                'STATUS' => 0,
                'SCALE' => 0,
                'CVE_ALM' => '',
                'ALMACEN' => '',
                'AV' => 0,
                'AC' => 0,
                'AD' => 0,
                'AE' => 0,
                'CVE_AGT' => 0,
                'CRTLSES' => '',
                'VE' => '',
                'REIMPRPT' => '',
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
            return response()->json([
                'message' => 'Error al crear usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Sign out - Cerrar sesión
     */
    public function signOut(Request $request)
    {
        try {
            // Aquí puedes agregar lógica adicional como invalidar el token
            // en una tabla de tokens revocados si lo necesitas

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

        Log::info('Correo enviado desde el front:' . $request->email);

        $usuario = Usuario::where('CORREO', $request->email)->first();

        if (!$usuario) {
            return response()->json(['message' => 'Si el email existe, recibirás instrucciones'], 200);
        }

        // 🔥 GENERAR TOKEN
        $token = Str::random(60);

        // Eliminar tokens previos
        DB::table('PASSWORD_RESET')->where('USUARIO_ID', $usuario->CLAVE)->delete();

        // Insertar token nuevo
        DB::table('PASSWORD_RESET')->insert([
            'EMAIL' => $usuario->CORREO,
            'TOKEN' => $token,
            'USUARIO_ID' => $usuario->CLAVE,
            'CREATED_AT' => Carbon::now(),
        ]);

        // ✔ DEFINIR VARIABLES
        $email = $usuario->CORREO;
        $user  = $usuario;

        // ✔ Enviar correo
        Mail::to($email)->send(new ForgotPasswordMail($token, $email, $user));

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
                return response()->json([
                    'message' => 'Datos inválidos'
                ], 422);
            }

            // Aquí validarías el token de recuperación
            // y actualizarías la contraseña

            return response()->json([
                'message' => 'Contraseña actualizada exitosamente'
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en resetPassword: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al restablecer contraseña'
            ], 500);
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
                return response()->json([
                    'message' => 'Datos inválidos'
                ], 422);
            }

            $usuario = Usuario::where('CORREO', $request->email)->first();

            if (!$usuario || !Hash::check($request->password, $usuario->PASSWORD)) {
                return response()->json([
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            $token = $this->generateToken($usuario);

            return response()->json([
                'accessToken' => $token
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en unlockSession: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al desbloquear sesión'
            ], 500);
        }
    }

    /**
     * Generar token JWT
     */
    private function generateToken(Usuario $usuario)
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $this->jwtExpiration;

        $payload = [
            'iss' => env('APP_URL', 'http://localhost'), // Issuer
            'sub' => $usuario->CLAVE, // Subject (ID del usuario)
            'iat' => $issuedAt, // Issued at
            'exp' => $expirationTime, // Expiration time
            'email' => $usuario->CORREO,
            'usuario' => $usuario->USUARIO,
        ];

        return JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);
    }

    /**
     * Generar CLAVE única para nuevo usuario
     * Solo números, porque CLAVE es numérica en la BD
     */
    private function generateUniqueClave()
    {
        do {
            $clave = rand(10000, 99999); // número de 5 dígitos
        } while (Usuario::where('CLAVE', $clave)->exists());

        return $clave;
    }
}
