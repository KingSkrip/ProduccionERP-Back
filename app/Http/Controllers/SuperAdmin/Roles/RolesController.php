<?php

namespace App\Http\Controllers\SuperAdmin\Roles;

use App\Helpers\ValidationMessages;
use App\Http\Controllers\Controller;
use App\Models\Rol;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class RolesController extends Controller
{
    /**
     * Listar todos los roles
     */
    public function index()
    {
        try {
            $roles = Rol::orderBy('CLAVE', 'DESC')->get();
            return response()->json([
                'ok' => true,
                'data' => $roles
            ], 200);
        } catch (Throwable $e) {
            Log::error("Error al obtener roles: " . $e->getMessage());
            return response()->json(['ok' => false, 'msg' => 'Error interno al obtener roles'], 500);
        }
    }

    /**
     * Crear un nuevo rol
     */


    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'NOMBRE' => 'required|string|max:120|unique:ROLES,NOMBRE',
            ],
            ValidationMessages::Messages()
        );

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $now = Carbon::now();

            // Convertir el nombre a mayúsculas antes de insertar
            $nombreMayusculas = strtoupper($request->NOMBRE);

            // Insert manual con GUARD_NAME por defecto
            DB::table('ROLES')->insert([
                'NOMBRE'     => $nombreMayusculas,
                'GUARD_NAME' => 'web',
                'CREATED_AT' => $now,
            ]);

            // Obtener el registro insertado con TODOS los campos
            $rol = DB::table('ROLES')
                ->where('NOMBRE', $nombreMayusculas)
                ->select('CLAVE', 'NOMBRE', 'GUARD_NAME', 'CREATED_AT', 'UPDATED_AT')
                ->first();

            return response()->json([
                'ok' => true,
                'msg' => 'Rol creado correctamente',
                'data' => $rol
            ], 201);
        } catch (Throwable $e) {
            Log::error("Error al crear rol: " . $e->getMessage());
            return response()->json([
                'ok' => false,
                'msg' => 'Error interno al crear rol: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Obtener un rol específico
     */
    public function show($id)
    {
        try {
            $rol = Rol::findOrFail($id);

            return response()->json([
                'ok' => true,
                'data' => $rol
            ], 200);
        } catch (Exception $e) {
            return response()->json(['ok' => false, 'msg' => 'Rol no encontrado'], 404);
        }
    }

    /**
     * Actualizar un rol
     */
    public function update(Request $request, $id)
    {
        try {
            $rol = Rol::findOrFail($id);

            $validator = Validator::make(
                $request->all(),
                [
                    'NOMBRE'     => 'required|string|max:120|unique:ROLES,NOMBRE,' . $id . ',CLAVE',
                    'GUARD_NAME' => 'required|string|max:120',
                ],
                ValidationMessages::Messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'ok'   => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $rol->update([
                'NOMBRE'     => $request->NOMBRE,
                'GUARD_NAME' => $request->GUARD_NAME,
            ]);

            DB::commit();

            return response()->json([
                'ok' => true,
                'msg' => 'Rol actualizado correctamente',
                'data' => $rol
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['ok' => false, 'msg' => 'Rol no encontrado'], 404);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Error al actualizar rol: " . $e->getMessage());
            return response()->json(['ok' => false, 'msg' => 'Error interno al actualizar rol'], 500);
        }
    }

    /**
     * Eliminar un rol
     */
    public function destroy($id)
    {
        try {
            $rol = Rol::findOrFail($id);

            DB::beginTransaction();

            $rol->delete();

            DB::commit();

            return response()->json([
                'ok' => true,
                'msg' => 'Rol eliminado correctamente'
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['ok' => false, 'msg' => 'Rol no encontrado'], 404);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Error al eliminar rol: " . $e->getMessage());
            return response()->json(['ok' => false, 'msg' => 'Error interno al eliminar rol'], 500);
        }
    }
}
