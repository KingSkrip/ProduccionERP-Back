<?php

namespace App\Http\Controllers\RH\Nominas\EmpresaUno;

use App\Helpers\ValidationMessages;
use App\Http\Controllers\Controller;

use App\Http\Resources\UsuarioResource;
use App\Models\ModelHasRole;
use App\Services\EmpresaUno\Empleados\EmpleadoE1Service;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EmpresaUnoController extends Controller
{

    protected $service;

    public function __construct()
    {
        $this->service = new EmpleadoE1Service();
    }

    /**
     * Muestra la lista de superadmins (usuarios con ROLE_CLAVE = 3).
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        try {
            $table = getDynamicTableName(); // tu helper

            // Valores por defecto:
            $offset = $request->query('offset', 0);
            $limit  = $request->query('limit', 50); // puedes cambiar 50 por lo que tú quieras

            $empleados = DB::connection('firebird_e1')
                ->table($table)
                ->skip($offset)
                ->take($limit)
                ->get();

            return response()->json([
                'message' => 'Empleados obtenidos correctamente',
                'data' => $empleados
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener empleados',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Retorna los datos necesarios para crear un superadmin (por ejemplo roles, configuraciones, etc.)
     * Para Angular, enviamos un JSON vacío si no se requiere información adicional.
     */
    public function create()
    {
        return response()->json([
            'message' => 'Formulario de creación de superadmin',
            'data' => []
        ], 200);
    }


    /**
     * Almacena un nuevo superadmin en la base de datos.
     */
    public function store(Request $request)
    {
        try {
            // Validar datos
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:USUARIOS,CORREO',
                    'password' => 'required|string|min:6',
                    'usuario' => 'nullable|string|max:255',
                    'departamento' => 'nullable|string|max:255',
                    'desktop' => 'nullable|string|max:255',
                    'photo' => 'nullable|file|image|mimes:jpeg,jpg,png,gif'
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $nextId = DB::connection('firebird')->selectOne(
                "SELECT GEN_ID(GEN_USUARIOS_ID, 1) AS ID FROM RDB\$DATABASE"
            )->ID;

            // ----------------------------------------
            // 📌 MANEJO DE FOTO
            // ----------------------------------------
            $photoPath = 'photos/users.jpg'; // ← FOTO DEFAULT

            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }

                $file = $request->file('photo');
                $filename = 'photo_' . $nextId . '_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);
                $photoPath = 'photos/' . $filename;
            }

            // Preparar valores
            $usuario = $request->input('usuario', 'COLABORADOR');
            $departamento = $request->input('departamento', '');
            $desktop = $request->input('desktop', '');

            // Insertar usuario
            DB::connection('firebird')->insert(
                "INSERT INTO USUARIOS (
                CLAVE, NOMBRE, USUARIO, CORREO, PASSWORD2, PERFIL, SESIONES,
                VERSION, FECHAACT, DEPTO, DEPARTAMENTO, STATUS, SCALE,
                CVE_ALM, ALMACEN, AV, AC, AD, AE, CVE_AGT, CTRLSES, VE, REIMPRPT,
                PHOTO, DESKTOP
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $nextId,
                    $request->name,
                    $usuario,
                    $request->email,
                    Hash::make($request->password),
                    0,
                    0,
                    1,
                    now()->format('Y-m-d H:i:s'),
                    0,
                    $departamento,
                    0,
                    0,
                    '',
                    '',
                    0,
                    0,
                    0,
                    0,
                    0,
                    '',
                    '',
                    '',
                    $photoPath,  // ← foto final
                    $desktop
                ]
            );

            // Insertar rol
            DB::connection('firebird')->insert(
                "INSERT INTO MODEL_HAS_ROLES (ROLE_CLAVE, MODEL_CLAVE, MODEL_TYPE)
            VALUES (?, ?, ?)",
                [1, $nextId, 'usuarios']
            );



            $usuario = Usuario::find($nextId);

            return response()->json([
                'message' => 'Superadmin creado exitosamente',
                'user' => [
                    'id' => $nextId,
                    'name' => $usuario->NOMBRE,
                    'email' => $usuario->CORREO,
                    'usuario' => $usuario->USUARIO,
                    'departamento' => $usuario->DEPARTAMENTO,
                    'desktop' => $usuario->DESKTOP,
                    'photo' => $usuario->PHOTO,
                ]
            ], 201);
        } catch (Exception $e) {



            Log::error('Error en store SuperAdmin: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al crear Superadmin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Obtiene los datos de un superadmin para editar (API para Angular)
     */
    public function edit($id)
    {
        try {
            $superadmin = Usuario::findOrFail($id);

            return response()->json([
                'message' => 'Datos obtenidos',
                'user' => [
                    'name' => $superadmin->NOMBRE,
                    'email' => $superadmin->CORREO,
                    'usuario' => $superadmin->USUARIO,
                    'photo' => $superadmin->PHOTO,
                    'departamento' => $superadmin->DEPARTAMENTO,
                    'desktop' => $superadmin->DESKTOP,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en edit ' . $e->getMessage());
            return response()->json([
                'message' => 'Datos no encontrados',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validación
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:USUARIOS,CORREO,' . $id . ',CLAVE',
                    'departamento' => 'nullable|string|max:255',
                    'desktop' => 'nullable|string|max:255',
                    'usuario' => 'nullable|string|max:255',
                    'current_password' => 'required_with:password|string',
                    'password' => 'nullable|string|min:6',
                    'photo' => 'nullable',
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->hasFile('photo')) {
                $validator = Validator::make(
                    $request->all(),
                    [
                        'photo' => 'file|image|mimes:jpeg,jpg,png,gif'
                    ],
                    ValidationMessages::messages()
                );

                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Datos inválidos',
                        'errors' => $validator->errors()
                    ], 422);
                }
            }


            $superadmin = Usuario::findOrFail($id);

            // ✔️ MANTENER FOTO ACTUAL
            $photoPath = $superadmin->PHOTO;

            // ✔️ SI SE ENVÍA UNA NUEVA FOTO
            if ($request->hasFile('photo')) {

                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }

                $file = $request->file('photo');
                $filename = 'photo_' . $id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);

                $photoPath = 'photos/' . $filename;
            }

            // ✔️ CONTRASEÑA
            if ($request->filled('password')) {
                $loggedUser = auth()->user();

                if (!Hash::check($request->current_password, $loggedUser->PASSWORD2)) {
                    return response()->json([
                        'message' => 'Contraseña actual incorrecta',
                    ], 403);
                }

                $superadmin->PASSWORD2 = Hash::make($request->password);
            }

            // ✔️ ACTUALIZAR CAMPOS
            $superadmin->update([
                'NOMBRE' => $request->name,
                'CORREO' => $request->email,
                'DEPARTAMENTO' => $request->departamento ?? $superadmin->DEPARTAMENTO,
                'DESKTOP' => $request->desktop ?? $superadmin->DESKTOP,
                'USUARIO' => $request->usuario ?? $superadmin->USUARIO,
                'PHOTO' => $photoPath, // ← SOLO CAMBIA SI SE ENVÍA FOTO
            ]);

            return response()->json([
                'message' => 'Superadmin actualizado correctamente',
                'user' => [
                    'id' => $superadmin->CLAVE,
                    'name' => $superadmin->NOMBRE,
                    'email' => $superadmin->CORREO,
                    'departamento' => $superadmin->DEPARTAMENTO,
                    'desktop' => $superadmin->DESKTOP,
                    'usuario' => $superadmin->USUARIO,
                    'photo' => $superadmin->PHOTO,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en update SuperAdmin: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al actualizar Superadmin',
                'error' => $e->getMessage()
            ], 500);
        }
    }




    public function destroy($id)
    {
        try {
            $loggedUserId = auth()->user()->CLAVE;
            if ((string)$loggedUserId === (string)$id) {
                return response()->json([
                    'message' => 'No puedes eliminar el usuario autenticado.'
                ], 403);
            }
            $superadmin = Usuario::findOrFail($id);
            $responseUser = [
                'id' => $superadmin->CLAVE,
                'name' => $superadmin->NOMBRE,
                'email' => $superadmin->CORREO,
                'photo' => $superadmin->PHOTO ?? null,
            ];

            try {
                ModelHasRole::where('MODEL_CLAVE', $id)
                    ->where('MODEL_TYPE', 'usuarios')
                    ->delete();
            } catch (Throwable $ex) {
                DB::connection('firebird')->table('MODEL_HAS_ROLES')
                    ->where('MODEL_CLAVE', $id)
                    ->where('MODEL_TYPE', 'usuarios')
                    ->delete();
            }

            $defaultPhoto = 'photos/users.jpg';
            if ($superadmin->PHOTO && $superadmin->PHOTO !== $defaultPhoto) {
                $photoPath = public_path($superadmin->PHOTO);
                if (file_exists($photoPath) && is_writable($photoPath)) {
                    @unlink($photoPath);
                }
            }

            $superadmin->delete();
            DB::connection('firebird')->commit();

            return response()->json([
                'message' => 'Superadmin eliminado correctamente',
                'user' => $responseUser
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::connection('firebird')->rollBack();
            Log::warning('Intento de eliminar usuario no encontrado: ' . $e->getMessage());
            return response()->json([
                'message' => 'Usuario no encontrado',
                'error' => $e->getMessage()
            ], 404);
        } catch (Exception $e) {
            try {
                DB::connection('firebird')->rollBack();
            } catch (Throwable $rollEx) {
                // ignorar
            }

            Log::error('Error en destroy SuperAdmin: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al eliminar Superadmin',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
