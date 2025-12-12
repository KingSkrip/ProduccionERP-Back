<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Rol;
use App\Models\Subrole;
use App\Models\Status;
use Exception;
use Illuminate\Http\Request;

class CatalogosController extends Controller
{
    /**
     * Retorna todos los catálogos generales.
     */
    public function getAllCatalogos()
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Catálogos cargados correctamente.',
                'data' => [
                    'departamentos' => Departamento::select('id', 'nombre')->get(),
                    'roles'         => Rol::select('id', 'nombre', 'guard_name')->get(),
                    'subroles'      => Subrole::select('id', 'nombre', 'guard_name')->get(),
                    'statuses'      => Status::select('id', 'nombre', 'descripcion')->get(),
                ]
            ], 200);
        } catch (Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los catálogos.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Catálogo: Departamentos
     */
    public function getDepartamentos()
    {
        try {
            $data = Departamento::select('id', 'nombre')->get();

            return response()->json([
                'success' => true,
                'message' => 'Departamentos cargados correctamente.',
                'data'    => $data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar departamentos.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Catálogo: Roles
     */
    public function getRoles()
    {
        try {
            $data = Rol::select('id', 'nombre', 'guard_name')->get();

            return response()->json([
                'success' => true,
                'message' => 'Roles cargados correctamente.',
                'data'    => $data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar roles.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Catálogo: Subroles
     */
    public function getSubroles()
    {
        try {
            $data = Subrole::select('id', 'nombre', 'guard_name')->get();

            return response()->json([
                'success' => true,
                'message' => 'Subroles cargados correctamente.',
                'data'    => $data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar subroles.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Catálogo: Status
     */
    public function getStatuses()
    {
        try {
            $data = Status::select('id', 'nombre', 'descripcion')->get();

            return response()->json([
                'success' => true,
                'message' => 'Statuses cargados correctamente.',
                'data'    => $data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar statuses.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
