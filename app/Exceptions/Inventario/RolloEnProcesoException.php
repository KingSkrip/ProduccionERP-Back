<?php

namespace App\Exceptions\Inventario;

use Exception;

class RolloEnProcesoException extends Exception
{
    public function __construct(int $clave)
    {
        parent::__construct("El rollo con clave {$clave} sigue en proceso de pesado/tejido, aún no ha terminado.");
    }
}