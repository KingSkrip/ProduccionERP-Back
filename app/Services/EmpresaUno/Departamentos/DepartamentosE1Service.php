<?php

namespace App\Services\EmpresaUno\Departamentos;

use Illuminate\Support\Facades\DB;

class DepartamentoE1Service
{
    private $connection = 'firebird_e1'; // conexión EMPRESA 1
    private $table = 'DEPTOS01';   // tabla estática

    // LISTAR
    public function all()
    {
        return DB::connection($this->connection)
                 ->table($this->table)
                 ->orderBy('CLAVE')
                 ->get();
    }

    // BUSCAR POR CLAVE
    public function find($clave)
    {
        return DB::connection($this->connection)
                 ->table($this->table)
                 ->where('CLAVE', $clave)
                 ->first();
    }

    // CREAR
    public function create($data)
    {
        return DB::connection($this->connection)
                 ->table($this->table)
                 ->insert($data);
    }

    // ACTUALIZAR
    public function update($clave, $data)
    {
        return DB::connection($this->connection)
                 ->table($this->table)
                 ->where('CLAVE', $clave)
                 ->update($data);
    }

    // ELIMINAR
    public function delete($clave)
    {
        return DB::connection($this->connection)
                 ->table($this->table)
                 ->where('CLAVE', $clave)
                 ->delete();
    }
}
