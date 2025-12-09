<?php

namespace App\Services\EmpresaUno\Empleados;

use Illuminate\Support\Facades\DB;

class EmpleadoE1Service
{
    private $connection = 'firebird_e1'; // conexión EMPRESA 1

    // Obtener tabla dinámica
    private function table($date = null)
    {
        return getDynamicTableName($date); // → TB07122501 etc.
    }

    // LISTAR
    public function all($date = null)
    {
        return DB::connection($this->connection)
                 ->table($this->table($date))
                 ->get();
    }

    // BUSCAR POR CLAVE
    public function find($clave, $date = null)
    {
        return DB::connection($this->connection)
                 ->table($this->table($date))
                 ->where('CLAVE', $clave)
                 ->first();
    }

    // CREAR
    public function create($data, $date = null)
    {
        return DB::connection($this->connection)
                 ->table($this->table($date))
                 ->insert($data);
    }

    // ACTUALIZAR
    public function update($clave, $data, $date = null)
    {
        return DB::connection($this->connection)
                 ->table($this->table($date))
                 ->where('CLAVE', $clave)
                 ->update($data);
    }

    // ELIMINAR
    public function delete($clave, $date = null)
    {
        return DB::connection($this->connection)
                 ->table($this->table($date))
                 ->where('CLAVE', $clave)
                 ->delete();
    }
}
