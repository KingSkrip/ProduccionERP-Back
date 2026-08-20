<?php
// app/Exceptions/Inventario/RolloNoEncontradoException.php

namespace App\Exceptions\Inventario;

use Exception;

class RolloNoEncontradoException extends Exception
{
    public function __construct(public readonly int $clave)
    {
        parent::__construct("No se encontró ningún rollo con la clave {$clave}.");
    }
}