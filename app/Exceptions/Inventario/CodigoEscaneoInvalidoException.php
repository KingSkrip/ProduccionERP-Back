<?php
// app/Exceptions/Inventario/CodigoEscaneoInvalidoException.php

namespace App\Exceptions\Inventario;

use Exception;

class CodigoEscaneoInvalidoException extends Exception
{
    public function __construct(public readonly string $codigoOriginal)
    {
        parent::__construct('Código escaneado inválido.');
    }
}